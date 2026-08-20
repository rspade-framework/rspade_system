#!/bin/bash

# RSpade VS Code Extension Installation Script

echo "RSpade VS Code Extension Installer"
echo "================================="
echo ""

# Check if npm is installed
if ! command -v npm &> /dev/null; then
    echo "Error: npm is not installed. Please install Node.js and npm first."
    exit 1
fi

# Check if vsce is installed
if ! command -v vsce &> /dev/null; then
    echo "Installing vsce (Visual Studio Code Extension manager)..."
    npm install -g vsce
fi

# Install dependencies
echo "Installing dependencies..."
npm install

# Compile TypeScript
echo "Compiling extension..."
npm run compile

# Package extension
echo "Packaging extension..."
vsce package --no-dependencies

# Find the generated vsix file
VSIX_FILE=$(ls -t *.vsix 2>/dev/null | head -n1)

if [ -z "$VSIX_FILE" ]; then
    echo "Error: No .vsix file was generated"
    exit 1
fi

echo ""
echo "Extension packaged successfully: $VSIX_FILE"
echo ""
echo "To install in VS Code:"
echo "1. Open VS Code"
echo "2. Press Ctrl+Shift+X to open Extensions"
echo "3. Click the '...' menu and select 'Install from VSIX...'"
echo "4. Select: $(pwd)/$VSIX_FILE"
echo ""
echo "Or install from command line:"
echo "code --install-extension \"$(pwd)/$VSIX_FILE\""