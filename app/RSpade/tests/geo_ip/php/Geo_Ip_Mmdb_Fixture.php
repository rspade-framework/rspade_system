<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\GeoIp\Php;

/**
 * Writes tiny MaxMind DB (MMDB) files for the geo_ip tests: an IPv4 tree, 24-bit records,
 * one data record per network, and the metadata the update's verification reads. No network,
 * no external writer - the format (https://maxmind.github.io/MaxMind-DB/) is small enough to
 * encode here for the handful of types the fixtures need: map, UTF-8 string, uint16, uint32,
 * uint64, array.
 */
class Geo_Ip_Mmdb_Fixture
{
    /** The metadata marker that precedes the metadata map. */
    private const METADATA_MARKER = "\xAB\xCD\xEFMaxMind.com";

    /**
     * A DB-IP-City-shaped record.
     */
    public static function city_record(?string $city, ?string $state, ?string $country, ?string $iso): array
    {
        $record = [];
        if ($city !== null) {
            $record['city'] = ['names' => ['en' => $city]];
        }
        if ($state !== null) {
            $record['subdivisions'] = [['names' => ['en' => $state]]];
        }
        if ($country !== null || $iso !== null) {
            $record['country'] = array_filter(['iso_code' => $iso, 'names' => $country !== null ? ['en' => $country] : null], fn ($v) => $v !== null);
        }

        return $record;
    }

    /**
     * A DB-IP-ASN-shaped record.
     */
    public static function asn_record(int $asn, string $organization): array
    {
        return ['autonomous_system_number' => ['__uint32', $asn], 'autonomous_system_organization' => $organization];
    }

    /**
     * Write an IPv4 MMDB file.
     *
     * @param string $path
     * @param array<string, array> $networks CIDR ('8.8.8.0/24') => record
     * @param string $database_type
     * @param int $build_epoch
     */
    public static function write(string $path, array $networks, string $database_type = 'DBIP-City-Lite', ?int $build_epoch = null): void
    {
        // The tree: node => [left, right], each null (empty), ['n', node] or ['d', data offset].
        $nodes = [[null, null]];
        $data = '';

        foreach ($networks as $cidr => $record) {
            [$address, $length] = explode('/', $cidr);
            $length = (int) $length;
            $bits = static::__bits(inet_pton($address));

            $offset = strlen($data);
            $data .= static::__encode($record);

            $node = 0;
            for ($i = 0; $i < $length - 1; $i++) {
                $bit = $bits[$i];
                $next = $nodes[$node][$bit];
                if ($next === null) {
                    $nodes[] = [null, null];
                    $next = ['n', count($nodes) - 1];
                    $nodes[$node][$bit] = $next;
                }
                if ($next[0] !== 'n') {
                    throw new \RuntimeException("Fixture network {$cidr} nests inside another network.");
                }
                $node = $next[1];
            }
            $nodes[$node][$bits[$length - 1]] = ['d', $offset];
        }

        $node_count = count($nodes);
        $tree = '';
        foreach ($nodes as [$left, $right]) {
            foreach ([$left, $right] as $record) {
                $value = match (true) {
                    $record === null => $node_count,
                    $record[0] === 'n' => $record[1],
                    default => $node_count + 16 + $record[1],
                };
                $tree .= substr(pack('N', $value), 1);
            }
        }

        $metadata = static::__encode([
            'node_count' => ['__uint32', $node_count],
            'record_size' => ['__uint16', 24],
            'ip_version' => ['__uint16', 4],
            'database_type' => $database_type,
            'languages' => ['en'],
            'binary_format_major_version' => ['__uint16', 2],
            'binary_format_minor_version' => ['__uint16', 0],
            'build_epoch' => ['__uint64', $build_epoch ?? time()],
            'description' => ['en' => 'RSpade geo_ip test fixture'],
        ]);

        file_put_contents($path, $tree . str_repeat("\0", 16) . $data . self::METADATA_MARKER . $metadata);
    }

    /** Write $networks as an MMDB and gzip it to $gz_path; answers the SHA1 of the unpacked file. */
    public static function write_gz(string $gz_path, array $networks, string $database_type = 'DBIP-City-Lite', ?int $build_epoch = null): string
    {
        $plain = $gz_path . '.plain';
        static::write($plain, $networks, $database_type, $build_epoch);
        $sha1 = sha1_file($plain);
        file_put_contents($gz_path, gzencode(file_get_contents($plain)));
        unlink($plain);

        return $sha1;
    }

    /** The bits of a packed address, most significant first. */
    private static function __bits(string $packed): array
    {
        $bits = [];
        foreach (str_split($packed) as $byte) {
            $value = ord($byte);
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        }

        return $bits;
    }

    /**
     * Encode one value. An array with sequential keys is an MMDB array; any other array a map;
     * ['__uint16'|'__uint32'|'__uint64', n] the unsigned integer.
     */
    private static function __encode(mixed $value): string
    {
        if (is_string($value)) {
            return static::__control(2, strlen($value)) . $value;
        }

        if (is_int($value)) {
            return static::__encode(['__uint32', $value]);
        }

        if (is_array($value) && count($value) === 2 && is_string($value[0] ?? null) && str_starts_with($value[0], '__uint')) {
            [$kind, $number] = $value;
            $bytes = ltrim(pack('J', $number), "\0");
            $type = ['__uint16' => 5, '__uint32' => 6, '__uint64' => 9][$kind];

            return static::__control($type, strlen($bytes)) . $bytes;
        }

        if (is_array($value) && array_is_list($value)) {
            $out = static::__control(11, count($value));
            foreach ($value as $item) {
                $out .= static::__encode($item);
            }

            return $out;
        }

        if (is_array($value)) {
            $out = static::__control(7, count($value));
            foreach ($value as $key => $item) {
                $out .= static::__encode((string) $key) . static::__encode($item);
            }

            return $out;
        }

        throw new \RuntimeException('The fixture writer cannot encode a ' . get_debug_type($value) . '.');
    }

    /** A control byte (plus extended type byte and size bytes) for $type and $size. */
    private static function __control(int $type, int $size): string
    {
        $extended = '';
        if ($type > 7) {
            $extended = chr($type - 7);
            $type = 0;
        }

        if ($size < 29) {
            return chr(($type << 5) | $size) . $extended;
        }
        if ($size < 285) {
            return chr(($type << 5) | 29) . $extended . chr($size - 29);
        }
        if ($size < 65821) {
            return chr(($type << 5) | 30) . $extended . pack('n', $size - 285);
        }

        return chr(($type << 5) | 31) . $extended . substr(pack('N', $size - 65821), 1);
    }
}
