/**
 * Condition evaluator — reads an action's `condition` without `eval`.
 *
 * A config provider declares when a row action is offered as a JavaScript expression:
 *
 *   'condition' => '!row.deletedAt'
 *   'condition' => 'row.status === "draft" || row.status === "sent"'
 *   'condition' => "!['completed', 'cancelled'].includes(row.status)"
 *   'condition' => "!(row.roles || []).some((role) => role === 'ROLE_SUPER_ADMIN')"
 *
 * ⚠️ It used to run through `new Function`, which a Content-Security-Policy without
 * `'unsafe-eval'` refuses: every row logged a violation, and once the policy is enforced every
 * conditional action would vanish from every table. This reads the part of JavaScript the
 * conditions actually use:
 *
 *   - literals: 'single' or "double" quoted strings, numbers, true, false, null, undefined,
 *     arrays `[a, b]`;
 *   - the names of the context (`row`, `user`) and member access, `row.site.name` or `row['x']`;
 *   - three calls: `.includes(x)` on an array or a string, `.some(item => …)` and
 *     `.every(item => …)` on an array, with a one-parameter arrow function;
 *   - `!` (any number of times) and unary `-`, `=== !== == != < <= > >=`, `&& ||`, parentheses.
 *
 * `&&` and `||` short-circuit and return an operand, as in JavaScript: `row.site && row.site.name`
 * never reads a property of null. A member read on null or undefined throws, as it would have.
 *
 * An expression outside that subset still runs through `new Function`, with a warning naming it:
 * an application that allows `'unsafe-eval'` keeps working, one that does not sees which
 * condition to rewrite.
 *
 * Usage:
 *   import { evaluateCondition } from '../services/condition';
 *
 *   evaluateCondition('!row.deletedAt', { row });   // true for a live row
 */

const compiled = new Map();

const PUNCTUATORS = ['===', '!==', '==', '!=', '=>', '<=', '>=', '&&', '||', '<', '>', '!', '-', '(', ')', '[', ']', '.', ','];
const LITERALS = { true: true, false: false, null: null, undefined };
const CALLBACK_METHODS = ['some', 'every'];

/** An expression the evaluator does not read — as opposed to one that fails on a row. */
class UnsupportedCondition extends Error {}

/**
 * @param {string} condition
 * @param {Object<string, *>} context the names an expression may read, e.g. `{ row, user }`
 * @returns {*} the value of the expression — the caller decides what is truthy
 * @throws {Error} when the expression fails on this context (a read on null, an unknown name)
 */
export function evaluateCondition(condition, context) {
    if (!compiled.has(condition)) {
        let evaluate;
        try {
            evaluate = compile(condition);
        } catch (error) {
            if (!(error instanceof UnsupportedCondition)) throw error;
            evaluate = legacy(condition, error);
        }
        compiled.set(condition, evaluate);
    }

    return compiled.get(condition)(context);
}

function legacy(condition, reason) {
    console.warn(`[DataTable] ${reason.message} — run through new Function, which a CSP without 'unsafe-eval' refuses.`);

    try {
        // eslint-disable-next-line no-new-func
        const run = new Function('context', `with(context) { return ${condition}; }`);
        return (context) => run(context);
    } catch (error) {
        return () => { throw error; };
    }
}

function compile(source) {
    const unsupported = (what) => new UnsupportedCondition(`${what} in condition: ${source}`);
    const tokens = tokenize(source);
    let position = 0;

    const peek = () => tokens[position];
    const isPunctuator = (value) => peek()?.type === 'punctuator' && peek().value === value;
    const expect = (value) => {
        if (!isPunctuator(value)) throw unsupported(`Expected "${value}"`);
        position++;
    };

    // Precedence climbs from || down to member access, the order JavaScript gives them.
    const binary = (operators, next) => () => {
        let left = next();
        while (peek()?.type === 'punctuator' && operators.includes(peek().value)) {
            const operator = tokens[position++].value;
            const right = next();
            left = combine(operator, left, right);
        }
        return left;
    };

    const unary = () => {
        if (isPunctuator('!')) {
            position++;
            const operand = unary();
            return (context) => !operand(context);
        }
        if (isPunctuator('-')) {
            position++;
            const operand = unary();
            return (context) => -operand(context);
        }
        return postfix();
    };

    const postfix = () => {
        let target = primary();
        for (;;) {
            if (isPunctuator('.')) {
                position++;
                const name = peek();
                if (name?.type !== 'name') throw unsupported('Expected a property name');
                position++;
                target = isPunctuator('(') ? call(target, name.value) : member(target, () => name.value);
            } else if (isPunctuator('[')) {
                position++;
                const key = or();
                expect(']');
                target = member(target, key);
            } else {
                return target;
            }
        }
    };

    const call = (target, method) => {
        if (method !== 'includes' && !CALLBACK_METHODS.includes(method)) throw unsupported(`Unsupported call ".${method}()"`);
        expect('(');
        const argument = CALLBACK_METHODS.includes(method) ? arrow() : or();
        expect(')');

        return (context) => {
            const value = target(context);
            const readable = Array.isArray(value) || (method === 'includes' && typeof value === 'string');
            if (!readable) throw new TypeError(`".${method}()" on ${value === null ? 'null' : typeof value} in condition: ${source}`);
            return value[method](argument(context));
        };
    };

    // `(item) => …` or `item => …`: one parameter, an expression body, read in the context plus it.
    const arrow = () => {
        const parenthesised = isPunctuator('(');
        if (parenthesised) position++;
        const parameter = peek();
        if (parameter?.type !== 'name') throw unsupported('Expected an arrow function parameter');
        position++;
        if (parenthesised) expect(')');
        expect('=>');
        const body = or();
        return (context) => (item) => body({ ...context, [parameter.value]: item });
    };

    const primary = () => {
        const token = tokens[position++];
        if (!token) throw unsupported('Unexpected end');

        if (token.type === 'number' || token.type === 'string') {
            return () => token.value;
        }
        if (token.type === 'name') {
            if (Object.hasOwn(LITERALS, token.value)) {
                const literal = LITERALS[token.value];
                return () => literal;
            }
            return (context) => {
                if (!Object.hasOwn(context, token.value)) {
                    throw new ReferenceError(`"${token.value}" is not defined in condition: ${source}`);
                }
                return context[token.value];
            };
        }
        if (token.value === '(') {
            const inner = or();
            expect(')');
            return inner;
        }
        if (token.value === '[') {
            const elements = [];
            while (!isPunctuator(']')) {
                elements.push(or());
                if (!isPunctuator(']')) expect(',');
            }
            position++;
            return (context) => elements.map((element) => element(context));
        }

        throw unsupported(`Unexpected "${token.value}"`);
    };

    const relational = binary(['<', '<=', '>', '>='], unary);
    const equality = binary(['===', '!==', '==', '!='], relational);
    const and = binary(['&&'], equality);
    const or = binary(['||'], and);

    const expression = or();
    if (position < tokens.length) throw unsupported(`Unexpected "${peek().value}"`);

    return expression;

    function tokenize(text) {
        const list = [];
        let i = 0;

        while (i < text.length) {
            const char = text[i];

            if (/\s/.test(char)) {
                i++;
                continue;
            }

            if (char === '"' || char === "'") {
                let value = '';
                i++;
                while (i < text.length && text[i] !== char) {
                    if (text[i] === '\\') {
                        i++;
                        value += { n: '\n', t: '\t', r: '\r' }[text[i]] ?? text[i];
                    } else {
                        value += text[i];
                    }
                    i++;
                }
                if (i >= text.length) throw unsupported('Unterminated string');
                i++;
                list.push({ type: 'string', value });
                continue;
            }

            const number = /^\d+(\.\d+)?/.exec(text.slice(i));
            if (number) {
                list.push({ type: 'number', value: Number(number[0]) });
                i += number[0].length;
                continue;
            }

            const name = /^[A-Za-z_$][\w$]*/.exec(text.slice(i));
            if (name) {
                list.push({ type: 'name', value: name[0] });
                i += name[0].length;
                continue;
            }

            const punctuator = PUNCTUATORS.find((candidate) => text.startsWith(candidate, i));
            if (!punctuator) throw unsupported(`Unexpected "${char}"`);
            list.push({ type: 'punctuator', value: punctuator });
            i += punctuator.length;
        }

        return list;
    }
}

function member(target, key) {
    return (context) => {
        const object = target(context);
        if (object === null || object === undefined) {
            throw new TypeError(`Cannot read "${key(context)}" of ${object}`);
        }
        return object[key(context)];
    };
}

function combine(operator, left, right) {
    switch (operator) {
        case '||': return (context) => left(context) || right(context);
        case '&&': return (context) => left(context) && right(context);
        case '===': return (context) => left(context) === right(context);
        case '!==': return (context) => left(context) !== right(context);
        // eslint-disable-next-line eqeqeq -- the loose comparison is what the condition asked for
        case '==': return (context) => left(context) == right(context);
        // eslint-disable-next-line eqeqeq
        case '!=': return (context) => left(context) != right(context);
        case '<': return (context) => left(context) < right(context);
        case '<=': return (context) => left(context) <= right(context);
        case '>': return (context) => left(context) > right(context);
        default: return (context) => left(context) >= right(context);
    }
}
