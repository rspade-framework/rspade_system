---
name: js-decorators
description: "Using and writing JavaScript decorators - @route, @spa, @layout, @auth, @mutex, @debounce, and custom decorators marked /** @decorator */ with the TC39 2023-11 (value, context) signature. Use when decorating an SPA action, serializing a method with @mutex, writing a decorator, or on 'FATAL: Decorator not whitelisted', 'FATAL: Class decorator not whitelisted', 'FATAL: Global function cannot use decorator', '@mutex can only be applied to methods' or a decorator failing on descriptor.value."
---

# RSX JavaScript Decorators

## Overview

RSX supports decorators on classes and on class methods (static and instance). They follow the **TC39 decorators proposal, version 2023-11**: a decorator is called with `(value, context)` - never the older `(target, key, descriptor)` signature, which fails at class definition on `descriptor.value`.

**Key difference**: a decorator applied to a class or a class method must be whitelisted with the `@decorator` marker on its definition (JS-DECORATOR-01, checked at manifest build).

---

## Common Framework Decorators

### @route - SPA Routing

Defines URL paths for SPA actions:

```javascript
@route('/contacts')
class Contacts_Index_Action extends Spa_Action { }

@route('/contacts/:id')
class Contacts_View_Action extends Spa_Action { }

// Dual routes (add/edit in one action)
@route('/contacts/add')
@route('/contacts/:id/edit')
class Contacts_Edit_Action extends Spa_Action { }
```

### @spa - SPA Bootstrap

Links action to its SPA bootstrap controller:

```javascript
@route('/contacts')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Contacts_Index_Action extends Spa_Action { }
```

### @auth - MANDATORY on every routed action

Every `@route` action declares a gate; the manifest build FAILS without one. `@auth('public')` is the explicit open marker - there is no attribute-free spelling of "open". See the auth-gates documentation for the check vocabulary.

### @layout - Layout Assignment

Assigns layout(s) to action:

```javascript
@route('/contacts')
@layout('Frontend_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('is_logged_in')
class Contacts_Index_Action extends Spa_Action { }

// Nested layouts (sublayouts)
@route('/settings/general')
@layout('Frontend_Layout')
@layout('Settings_Layout')
@spa('Frontend_Spa_Controller::index')
@auth('can_manage_site_settings')
class Settings_General_Action extends Spa_Action { }
```

### @mutex - Mutual Exclusion

Serializes an async method - bare for a per-instance lock, with an id for one lock shared by every instance:

```javascript
class My_Component extends Component {
    @mutex
    async save() {
        // Only one save() per instance runs at a time
        await Controller.save(this.vals());
    }

    @mutex('rebuild_index')
    async rebuild() { }   // one rebuild() at a time across all instances
}
```

### @debounce - Debounced Method

```javascript
class Contact_Search extends Component {
    @debounce(250)
    async search(term) { }
}
```

---

## Decorator Restrictions

### Classes And Methods Must Use Whitelisted Decorators

Any decorator on a class method - static or instance - must be marked `@decorator` on its definition, or the manifest build fails with `FATAL: Decorator not whitelisted`. A decorator on a CLASS is held to the same whitelist: an unknown or misspelled one (`@rout('/x')`) fails with `FATAL: Class decorator not whitelisted`, naming the file, line, class and decorator and listing the whitelisted names.

- A decorator is whitelisted under its BARE name: a member expression (`@Some_Class.some_method`) fails on a class and on a method alike - apply a static decorator method by its bare name.
- A JSDoc marker in the class's docblock (`@Instantiatable`) is a comment, not a decorator, and is never checked.
- The check runs whether or not `rsx.javascript.decorators` is enabled.

### Global Functions Carry Only The Marker

A global function may carry `@decorator` and nothing else (`FATAL: Global function cannot use decorator`).

### No Class Name Identifiers in Parameters

Decorator parameters **must not** use class name identifiers (bundle ordering issues, JS-DECORATOR-IDENT-01):

```javascript
// WRONG - class identifier as parameter
@some_decorator(User_Model)
class My_Action extends Spa_Action { }

// CORRECT - use string literal
@some_decorator('User_Model')
class My_Action extends Spa_Action { }
```

**Why**: Bundle compilation doesn't guarantee class definition order for decorator parameters.

### Multiple Decorators

The expressions are evaluated top to bottom, then APPLIED bottom to top - the decorator nearest the declaration runs first, and each wraps what the one below returned:

```javascript
@log_calls('outer')    // Applied second: wraps the debounced method
@debounce(250)         // Applied first: wraps the original method
static transform(data) { }
```

This is why each `@layout` prepends: written outermost first, the chain still ends up outermost first.

---

## The Signature (TC39 2023-11)

```javascript
function my_decorator(value, context) {
    // value:   the class (class decorator) or the method function (method decorator)
    // context: { kind, name, static, private, access, addInitializer }
    //          kind is 'class' | 'method' | 'getter' | 'setter' | 'field' | 'accessor'
    // Return a replacement class/function, or nothing to keep the original.
}
```

A decorator with arguments is a factory: `@route('/x')` calls `route('/x')` and applies what it returns.

---

## Creating Custom Decorators

Mark the definition `@decorator` in its JSDoc (in a file with no class, a bare `@decorator` line above the function also works).

### Class Decorator (the shape of `@route`)

```javascript
/**
 * @decorator
 */
function section(section_name) {
    return function (value, context) {
        value._section = section_name;
        return value;
    };
}
```

### Method Decorator (the shape of `@debounce`)

```javascript
/**
 * @decorator
 */
function log_calls(label) {
    return function (value, context) {
        if (context.kind !== 'method') {
            throw new Error(`@log_calls can only be applied to methods, not ${context.kind}`);
        }
        return function (...args) {
            console.log(`${label}: ${context.name}`, args);
            return value.apply(this, args);
        };
    };
}
```

### Without Arguments (the shape of `@mutex`)

Applied bare, the decorator itself receives `(value, context)`:

```javascript
/**
 * @decorator
 */
function trace(value, context) {
    return function (...args) {
        console.log(`enter ${context.name}`);
        return value.apply(this, args);
    };
}
```

**Important**: a replacement method is a plain `function` that calls `value.apply(this, args)`, so it keeps its receiver. Check `context.kind` and throw on the wrong kind, as `@mutex` does.

### Per-Instance Setup

```javascript
/**
 * @decorator
 */
function bound(value, context) {
    context.addInitializer(function () {
        this[context.name] = this[context.name].bind(this);
    });
}
```

---

## Troubleshooting

| Error | Solution |
|-------|----------|
| `FATAL: Decorator not whitelisted` | Add the `@decorator` marker to the decorator's definition |
| `FATAL: Class decorator not whitelisted` | Correct a misspelling to a listed decorator, mark your own decorator's definition `@decorator`, or move a marker such as `@Instantiatable` into the class docblock |
| `FATAL: Global function cannot use decorator` | Remove it; a global function carries only `@decorator` |
| `Cannot read properties of undefined (reading 'value')` at class definition | The decorator uses the older `(target, key, descriptor)` signature - rewrite it to `(value, context)` |
| `@mutex can only be applied to methods` | Apply it to a method; `@mutex()` with empty parentheses is the same error - write `@mutex` |

## More Information

Details: `php artisan rsx:man js_decorators`
