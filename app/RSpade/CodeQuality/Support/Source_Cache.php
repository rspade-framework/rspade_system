<?php

namespace App\RSpade\CodeQuality\Support;

use PhpParser\Error as PhpParser_Error;
use PhpParser\ParserFactory;

/**
 * The ONE place a build reads, tokenizes or parses a source file.
 *
 * WHY THIS EXISTS. Every rule that needed a token stream or an AST used to make its own,
 * and hold it in a static array for the life of the process. A cold build of 1,417 files
 * ended up with 2,093 nikic parses (~3.6 per PHP file), 470 MB of retained `PhpToken`
 * streams and ~400 MB of retained AST - 1.12 GB of a 1,237 MB peak, all of it inside the
 * manifest-time code-quality pass, and all of it proportional to FILES PARSED rather than
 * to the index the build is producing.
 *
 * THE BUDGET this class implements (owner ruling): peak memory is proportional to the
 * index plus a constant bounded by the LARGEST SINGLE FILE, never proportional to the
 * number of files parsed. So the cache is an LRU with a hard entry bound: a build may
 * parse ten thousand files and still hold at most `$capacity` of them at once. Nothing
 * here is static - the instance belongs to the build, and `release()` empties it.
 *
 * WHAT IT MEMOIZES, per path:
 *   - content()  the raw bytes;
 *   - tokens()   `PhpToken::tokenize()` output;
 *   - ast()      one nikic parse, using ONE parser instance for the whole build.
 *
 * Each is lazy and independent: asking for an AST does not materialize a token stream.
 * A path that cannot be read is memoized as empty/null, so a missing file costs one stat
 * and not one per asker.
 *
 * KEYING. The manifest file hash is the honest key when the caller has metadata - a
 * rule's answer is about the file's BYTES, and the hash is what says the bytes changed.
 * `content($path, $hash)` accepts it; without one the path is the key and the entry lives
 * only as long as this instance (a build never reads a file it is also rewriting without
 * calling `forget()` in between - `Php_Fixer` does exactly that after every write).
 *
 * FAILURE POSTURE. An unreadable file yields `''` / `[]` / `null` rather than throwing:
 * a build routinely names files that vanished between the scan and the check, and every
 * caller here is a checker that has something to say about a file that IS there. A
 * SYNTAX error likewise yields a null AST - the lint stage is what reports syntax, and a
 * rule must not turn an unparseable file into its own kind of failure.
 *
 * See: rsx:man code_quality.
 */
#[Instantiatable]
class Source_Cache
{
    /** Default number of files held at once. Bounded, not tuned: see the budget above. */
    public const DEFAULT_CAPACITY = 64;

    /** Maximum entries per bucket. */
    private int $capacity;

    /** [key => string] raw file contents, most-recently-used last. */
    private array $content = [];

    /** [key => array<string,?object>] class nodes found in a file's AST, by class name. */
    private array $class_nodes = [];

    /**
     * [key => array<string,array>] per-class MEMBER SUMMARIES, by lowercased class name.
     *
     * NOT bound by $capacity, and that is deliberate - see declared_members().
     */
    private array $members = [];

    /** [key => array] PhpToken streams, most-recently-used last. */
    private array $tokens = [];

    /** [key => array|null] nikic statement arrays (null = unparseable), MRU last. */
    private array $ast = [];

    /** The one nikic parser for this instance's lifetime. */
    private ?object $parser = null;

    /** Counters, for the build's own reporting: how many times each bucket did real work. */
    private array $work = ['content' => 0, 'tokens' => 0, 'ast' => 0, 'members' => 0];

    public function __construct(int $capacity = self::DEFAULT_CAPACITY)
    {
        if ($capacity < 1) {
            shouldnt_happen('Source_Cache capacity must be at least 1, got ' . $capacity);
        }

        $this->capacity = $capacity;
    }

    /**
     * The raw bytes of $path ('' when it cannot be read).
     *
     * $hash is the manifest's file hash when the caller has one; it makes the entry
     * survive a path being asked for under two spellings and, more importantly, makes the
     * key say what the entry is actually about.
     */
    public function content(string $path, string $hash = ''): string
    {
        $key = $this->__key($path, $hash);

        if (array_key_exists($key, $this->content)) {
            return $this->__touch($this->content, $key);
        }

        $value = '';

        if (is_file($path) && is_readable($path)) {
            $read = file_get_contents($path);
            $value = $read === false ? '' : $read;
        }

        $this->work['content']++;

        return $this->__store($this->content, $key, $value);
    }

    /**
     * `PhpToken::tokenize()` over $path ([] when the file cannot be read).
     */
    public function tokens(string $path, string $hash = ''): array
    {
        $key = $this->__key($path, $hash);

        if (array_key_exists($key, $this->tokens)) {
            return $this->__touch($this->tokens, $key);
        }

        $source = $this->content($path, $hash);
        $value = $source === '' ? [] : \PhpToken::tokenize($source);

        $this->work['tokens']++;

        return $this->__store($this->tokens, $key, $value);
    }

    /**
     * The nikic statement array for $path, or null when the file cannot be read or does
     * not parse.
     */
    public function ast(string $path, string $hash = ''): ?array
    {
        $key = $this->__key($path, $hash);

        if (array_key_exists($key, $this->ast)) {
            return $this->__touch($this->ast, $key);
        }

        $source = $this->content($path, $hash);
        $value = null;

        if ($source !== '') {
            try {
                $value = $this->__parser()->parse($source);
            } catch (PhpParser_Error $error) {
                $value = null;
            }
        }

        $this->work['ast']++;

        $this->__store($this->ast, $key, $value);

        // The class-node map is a satellite of the AST bucket: whatever the store just
        // evicted must lose its class nodes too, or the satellite would outlive the bound.
        $this->class_nodes = array_intersect_key($this->class_nodes, $this->ast);

        return $value;
    }

    /**
     * The nikic ClassLike node named $class_name inside $path, or null when the file does
     * not parse or does not declare it.
     *
     * FOUR CROSS-FILE RULES wrote this lookup privately (PHP-PARENT-CHAIN-01, SEALED-01,
     * REVISION-01, POLY-01), and each one ran a full `NodeFinder` traversal of the file's
     * AST every time it asked. PHP-PARENT-CHAIN-01 asks once per ANCESTOR per METHOD, so a
     * twenty-method class four levels deep cost eighty whole-AST traversals to answer five
     * distinct questions: 3.9 s of a 14.4 s cold build, and the single largest item in it.
     *
     * The answer is memoized beside the AST it came from and evicted with the file, so it
     * obeys the same bound as everything else here.
     */
    public function class_node(string $path, string $class_name, string $hash = ''): ?object
    {
        $key = $this->__key($path, $hash);
        $lower = strtolower($class_name);

        if (isset($this->class_nodes[$key]) && array_key_exists($lower, $this->class_nodes[$key])) {
            return $this->class_nodes[$key][$lower];
        }

        $ast = $this->ast($path, $hash);
        $found = null;

        if ($ast !== null) {
            foreach ((new \PhpParser\NodeFinder())->findInstanceOf($ast, \PhpParser\Node\Stmt\ClassLike::class) as $class_like) {
                if ($class_like->name !== null && strcasecmp($class_like->name->toString(), $class_name) === 0) {
                    $found = $class_like;

                    break;
                }
            }
        }

        // The AST bucket decides residency; this map only rides along with it, so an entry
        // the LRU has already evicted is not resurrected here.
        if (array_key_exists($key, $this->ast)) {
            $this->class_nodes[$key][$lower] = $found;
        }

        return $found;
    }

    /**
     * A flat, AST-FREE SUMMARY of what $class_name declares inside $path.
     *
     *   [
     *     'methods'    => [lower_name => [
     *                        'name', 'line', 'abstract', 'static', 'visibility',
     *                        'attributes'   => [lower simple attribute name => true],
     *                        'has_body'     => bool,
     *                        'parent_calls' => [lower method name => true] (plus '*' for
     *                                          a dynamic parent::{$x}() call),
     *                        'exceptions'   => [rule id => true],
     *                     ]],
     *     'properties' => [name => [
     *                        'name', 'line', 'static', 'visibility',
     *                        'attributes' => [...], 'exceptions' => [...],
     *                        'has_default', 'default' => scalar|string[]|null,
     *                     ]],
     *   ]
     *
     * WHY IT IS NOT BOUND BY $capacity, when the AST and the token stream are.
     *
     * The four ANCESTRY rules (PHP-PARENT-CHAIN-01, SEALED-01, REVISION-01, POLY-01) each
     * walk the whole indexed tree and ask structural questions about a class and its
     * ancestors. They ran one after another over the same ~700 files, and a 16-entry LRU
     * cannot hold 700 files, so every rule re-parsed every file: four full parses of the
     * tree, 5.2 s of a 10.3 s cold build. Sharing the AST could not fix that - the AST is
     * exactly the thing the budget forbids retaining.
     *
     * A summary is not. It holds no nikic nodes, only scalars and short lists, so its size
     * is proportional to the INDEX (a few kilobytes per class), which is precisely what the
     * memory budget permits to grow - "peak proportional to the index plus a constant
     * bounded by the largest single file". So the file is parsed ONCE for the whole pass and
     * every later question is answered from the summary; `release()` drops the lot.
     *
     * The fields are the union of what those four rules actually need, which is why
     * `parent_calls` and `exceptions` are in here rather than left to the caller: computing
     * them needs the AST, and the point of the summary is that the caller never holds one.
     */
    public function declared_members(string $path, string $class_name, string $hash = ''): array
    {
        $key = $this->__key($path, $hash);
        $lower = strtolower($class_name);

        if (isset($this->members[$key]) && array_key_exists($lower, $this->members[$key])) {
            return $this->members[$key][$lower];
        }

        $summary = ['methods' => [], 'properties' => []];
        $node = $this->class_node($path, $class_name, $hash);

        if ($node !== null) {
            $content = $this->content($path, $hash);
            $lines = explode("\n", $content);

            // A body walk is the one expensive part of this summary, and `parent::` is rare:
            // most files contain the token nowhere at all, and in a file that does, most
            // methods do not. Two string tests - the whole file, then the method's own line
            // range - decide whether the traversal happens. The AST stays authoritative; a
            // mention inside a comment or a string only costs one traversal that finds
            // nothing, and a method whose range has no mention cannot contain a real call.
            $file_mentions_parent = str_contains($content, 'parent::');

            foreach ($node->getMethods() as $method) {
                $name = $method->name->toString();
                $parent_calls = [];

                if ($file_mentions_parent
                    && $this->__range_mentions($lines, $method->getStartLine(), $method->getEndLine(), 'parent::')) {
                    $parent_calls = $this->__parent_calls($method);
                }

                $summary['methods'][strtolower($name)] = [
                    'name' => $name,
                    'line' => $method->getStartLine(),
                    'abstract' => $method->isAbstract(),
                    'static' => $method->isStatic(),
                    'visibility' => $this->__visibility_of($method),
                    'attributes' => $this->__attribute_names($method->attrGroups),
                    'has_body' => $method->stmts !== null,
                    'parent_calls' => $parent_calls,
                    'exceptions' => $this->__exception_ids($method, $lines),
                ];
            }

            foreach ($node->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    $name = $prop->name->toString();
                    $summary['properties'][$name] = [
                        'name' => $name,
                        'line' => $property->getStartLine(),
                        'static' => $property->isStatic(),
                        'visibility' => $this->__visibility_of($property),
                        'attributes' => $this->__attribute_names($property->attrGroups),
                        'exceptions' => $this->__exception_ids($property, $lines),
                        'has_default' => $prop->default !== null,
                        'default' => $this->__literal_default($prop->default),
                    ];
                }
            }
        }

        $this->work['members']++;
        $this->members[$key][$lower] = $summary;

        return $summary;
    }

    /**
     * 'public' | 'protected' | 'private' for any node carrying PHP's modifier flags.
     */
    private function __visibility_of(object $node): string
    {
        if (method_exists($node, 'isPrivate') && $node->isPrivate()) {
            return 'private';
        }

        if (method_exists($node, 'isProtected') && $node->isProtected()) {
            return 'protected';
        }

        return 'public';
    }

    /**
     * [lowercased SIMPLE attribute name => true] over a node's attribute groups.
     *
     * Simple names because a marker attribute is never a defined class (framework
     * convention), so the only thing an author can have written is its short name.
     */
    private function __attribute_names(array $attr_groups): array
    {
        $names = [];

        foreach ($attr_groups as $group) {
            foreach ($group->attrs as $attr) {
                $parts = explode('\\', $attr->name->toString());
                $names[strtolower((string) end($parts))] = true;
            }
        }

        return $names;
    }

    /**
     * Does any source line between $start and $end (1-based, inclusive) contain $needle?
     */
    private function __range_mentions(array $lines, int $start, int $end, string $needle): bool
    {
        for ($i = max(1, $start) - 1; $i < $end && isset($lines[$i]); $i++) {
            if (str_contains($lines[$i], $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which methods this method's body invokes through `parent::`.
     *
     * [lowercased name => true], plus `'*' => true` for a dynamic `parent::{$x}()` - the
     * parent IS invoked and the name is unprovable, so a caller asking "does this chain"
     * fails open, which is the contract PHP-PARENT-CHAIN-01 documents.
     */
    private function __parent_calls(object $method): array
    {
        if (!isset($method->stmts) || $method->stmts === null) {
            return [];
        }

        $calls = [];

        foreach ((new \PhpParser\NodeFinder())->findInstanceOf($method->stmts, \PhpParser\Node\Expr\StaticCall::class) as $call) {
            if (!($call->class instanceof \PhpParser\Node\Name)) {
                continue;
            }

            if (strcasecmp($call->class->toString(), 'parent') !== 0) {
                continue;
            }

            if ($call->name instanceof \PhpParser\Node\Identifier) {
                $calls[strtolower($call->name->toString())] = true;

                continue;
            }

            $calls['*'] = true;
        }

        return $calls;
    }

    /**
     * The rule ids named by an `@<RULE-ID>-EXCEPTION` marker attached to this member.
     *
     * Looks in the member's own comments, on its declaration line and on the line
     * immediately above it - the three places every rule that honors a per-member exception
     * has always looked. Returns [rule id => true], almost always empty.
     */
    private function __exception_ids(object $node, array $lines): array
    {
        $text = '';

        foreach ($node->getComments() as $comment) {
            $text .= $comment->getText() . "\n";
        }

        $index = $node->getStartLine() - 1;

        foreach ([$index, $index - 1] as $candidate) {
            if ($candidate >= 0 && isset($lines[$candidate])) {
                $text .= $lines[$candidate] . "\n";
            }
        }

        if (!str_contains($text, '-EXCEPTION')) {
            return [];
        }

        $ids = [];

        if (preg_match_all('/@([A-Z0-9][A-Z0-9-]*)-EXCEPTION/', $text, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * The LITERAL default of a property, when it is one this summary can carry:
     * a bool/int/float/string scalar, or a flat list of scalars. Anything else is null.
     *
     * The three ancestry questions asked of a default are `$revisions = true`,
     * `$type_ref_columns = ['a', 'b']` and `$table = '...'`; none of them needs an
     * expression, and a summary that carried one would be carrying AST again.
     */
    private function __literal_default(?object $default)
    {
        if ($default === null) {
            return null;
        }

        if ($default instanceof \PhpParser\Node\Scalar\String_
            || $default instanceof \PhpParser\Node\Scalar\Int_
            || $default instanceof \PhpParser\Node\Scalar\Float_) {
            return $default->value;
        }

        if ($default instanceof \PhpParser\Node\Expr\ConstFetch) {
            $name = strtolower($default->name->toString());

            if ($name === 'true') {
                return true;
            }

            if ($name === 'false') {
                return false;
            }

            if ($name === 'null') {
                return null;
            }

            return null;
        }

        if ($default instanceof \PhpParser\Node\Expr\Array_) {
            $items = [];

            foreach ($default->items as $item) {
                if ($item === null) {
                    continue;
                }

                $value = $this->__literal_default($item->value);

                if (is_array($value) || $value === null) {
                    continue;
                }

                $items[] = $value;
            }

            return $items;
        }

        return null;
    }

    /**
     * `PhpToken::tokenize()` over a string the caller already holds - the object shape, for
     * a caller checking CONTENT it was handed rather than a file on disk (a rule fixture the
     * test never wrote to that path).
     *
     * NOT memoized, for the same reason token_array() is not.
     */
    public function php_tokens_of(string $code): array
    {
        return $code === '' ? [] : \PhpToken::tokenize($code);
    }

    /**
     * `token_get_all()` over a string the caller already holds - a synthesized fragment, or
     * a file's content it was handed.
     *
     * The OTHER token shape, and deliberately a separate method: `tokens()` yields PhpToken
     * OBJECTS and this yields the array-and-scalar shape, and a walker written for one does
     * not read the other. NOT memoized - the caller made these bytes, so nobody else can ask
     * for them, and a memo keyed on content would just be a second copy of the fragment.
     */
    public function token_array(string $code): array
    {
        return $code === '' ? [] : token_get_all($code);
    }

    /**
     * Drop everything remembered about $path. Called after a write to it.
     */
    public function forget(string $path, string $hash = ''): void
    {
        $key = $this->__key($path, $hash);

        unset($this->content[$key], $this->tokens[$key], $this->ast[$key], $this->class_nodes[$key], $this->members[$key]);

        // A path written under one hash is also the path spelled with no hash.
        if ($hash !== '') {
            unset($this->content[$path], $this->tokens[$path], $this->ast[$path], $this->class_nodes[$path], $this->members[$path]);
        }
    }

    /**
     * Empty every bucket and drop the parser. The end of the pass that owns this instance.
     */
    public function release(): void
    {
        $this->content = [];
        $this->tokens = [];
        $this->ast = [];
        $this->class_nodes = [];
        $this->members = [];
        $this->parser = null;
    }

    /**
     * How many times each bucket did real work (reads, tokenizations, parses).
     *
     * @return array{content:int,tokens:int,ast:int,members:int}
     */
    public function work_counts(): array
    {
        return $this->work;
    }

    /**
     * How many entries each bucket is holding right now.
     */
    public function resident_counts(): array
    {
        return [
            'content' => count($this->content),
            'tokens' => count($this->tokens),
            'ast' => count($this->ast),
            'members' => count($this->members),
        ];
    }

    private function __key(string $path, string $hash): string
    {
        return $hash === '' ? $path : $hash;
    }

    private function __parser(): object
    {
        if ($this->parser === null) {
            $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        }

        return $this->parser;
    }

    /**
     * Move $key to the most-recently-used end and return its value.
     */
    private function __touch(array &$bucket, string $key)
    {
        $value = $bucket[$key];
        unset($bucket[$key]);
        $bucket[$key] = $value;

        return $value;
    }

    /**
     * Store $value under $key and evict the least-recently-used entry while the bucket is
     * over capacity. PHP arrays preserve insertion order, so the first key is the LRU one.
     */
    private function __store(array &$bucket, string $key, $value)
    {
        $bucket[$key] = $value;

        while (count($bucket) > $this->capacity) {
            $oldest = array_key_first($bucket);
            unset($bucket[$oldest]);
        }

        return $value;
    }
}
