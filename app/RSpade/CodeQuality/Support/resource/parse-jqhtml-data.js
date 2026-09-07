#!/usr/bin/env node

const fs = require('fs');
const acorn = require('acorn');
const walk = require('acorn-walk');

// Classes that are Jqhtml components
const JQHTML_COMPONENTS = new Set([
    'Component', '_Base_Jqhtml_Component', 'Component'
]);

function analyzeFile(filePath) {
    const code = fs.readFileSync(filePath, 'utf8');
    const lines = code.split('\n');

    let ast;
    try {
        ast = acorn.parse(code, {
            ecmaVersion: 2020,
            sourceType: 'module',
            locations: true
        });
    } catch (e) {
        // Parse error - return empty violations
        console.log(JSON.stringify({ violations: [] }));
        return;
    }

    const violations = [];
    let currentClass = null;
    let inOnCreate = false;

    // Helper to check if a class extends Component
    function isJqhtmlComponent(extendsClass) {
        if (!extendsClass) return false;
        return JQHTML_COMPONENTS.has(extendsClass) ||
               extendsClass.includes('Component') ||
               extendsClass.includes('Jqhtml');
    }

    // Walk the AST
    walk.simple(ast, {
        ClassDeclaration(node) {
            currentClass = {
                name: node.id.name,
                extends: node.superClass?.name,
                isJqhtml: isJqhtmlComponent(node.superClass?.name)
            };
        },

        ClassExpression(node) {
            currentClass = {
                name: node.id?.name || 'anonymous',
                extends: node.superClass?.name,
                isJqhtml: isJqhtmlComponent(node.superClass?.name)
            };
        },

        MethodDefinition(node) {
            // Check if this is on_create method
            if (node.key.name === 'on_create' && currentClass?.isJqhtml) {
                inOnCreate = true;

                // Walk the method body looking for this.data
                walk.simple(node.value.body, {
                    MemberExpression(memberNode) {
                        // Check for this.data pattern
                        if (memberNode.object.type === 'ThisExpression' &&
                            memberNode.property.name === 'data') {
                            // Found this.data in on_create
                            const lineContent = lines[memberNode.loc.start.line - 1] || '';
                            violations.push({
                                line: memberNode.loc.start.line,
                                column: memberNode.loc.start.column,
                                className: currentClass.name,
                                codeSnippet: lineContent.trim()
                            });
                        }
                    }
                });

                inOnCreate = false;
            }
        }
    });

    console.log(JSON.stringify({ violations }));
}

// Main
if (process.argv.length < 3) {
    console.error('Usage: node parse-jqhtml-data.js <file-path>');
    process.exit(1);
}

try {
    analyzeFile(process.argv[2]);
} catch (e) {
    console.error('Error:', e.message);
    console.log(JSON.stringify({ violations: [] }));
}
