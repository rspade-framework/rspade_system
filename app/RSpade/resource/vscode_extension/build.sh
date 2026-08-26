#!/bin/bash

# RSpade VS Code Extension Build Script
# Builds the extension inside the Docker container

set -e  # Exit on error

echo "╔═══════════════════════════════════════════╗"
echo "║   RSpade VS Code Extension Build Script   ║"
echo "╚═══════════════════════════════════════════╝"
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

# Options
#
# --release omits sourcemaps. tsc emits a .js.map beside every .js because
# tsconfig.json sets sourceMap:true, which is what you want while developing the
# extension - a stack trace in the Extension Host points at the TypeScript line.
# A published build has no use for them: .vscodeignore already keeps *.map out of
# the .vsix, so in a release they are written and then discarded. --release stops
# generating them at all, leaving out/ holding exactly what ships.
RELEASE=0
for arg in "$@"; do
    case "$arg" in
        --release)
            RELEASE=1
            ;;
        -h|--help)
            echo "Usage: ./build.sh [--release]"
            echo ""
            echo "  --release   Compile without sourcemaps (out/ then holds exactly"
            echo "              what the .vsix ships). Default builds with sourcemaps."
            exit 0
            ;;
        *)
            echo -e "${RED}✗ Unknown option: $arg${NC}" >&2
            echo "Usage: ./build.sh [--release]" >&2
            exit 1
            ;;
    esac
done

# Step 1: Check we're in Docker
if [ ! -f /.dockerenv ]; then
    echo -e "${YELLOW}⚠ Not running in Docker container${NC}"
    echo "This script is designed to run inside the RSpade Docker container"
    echo ""
    echo "To build from outside Docker, run:"
    echo "  docker exec -it <container-name> /var/www/html/app/RSpade/resource/vscode_extension/build.sh"
    exit 1
fi

echo -e "${GREEN}✓ Running in Docker container${NC}"

# Step 2: Clean previous builds
echo ""
echo "🧹 Cleaning previous builds..."
rm -rf out/
rm -f *.vsix
rm -rf node_modules/
rm -f package-lock.json
echo -e "${GREEN}✓ Cleaned${NC}"

# Step 3: Install dependencies
echo ""
echo "📦 Installing dependencies..."
npm install --no-save
echo -e "${GREEN}✓ Dependencies installed${NC}"

# Step 4: Compile TypeScript
echo ""
if [ "$RELEASE" -eq 1 ]; then
    echo "🔨 Compiling TypeScript (release - no sourcemaps)..."
    npx tsc -p ./ --sourceMap false
else
    echo "🔨 Compiling TypeScript..."
    npx tsc -p ./
fi
echo -e "${GREEN}✓ Compiled successfully${NC}"

# Step 5: Run linter (optional, allow failures)
echo ""
echo "🔍 Running linter..."
npx eslint src --ext ts || {
    echo -e "${YELLOW}⚠ Linting warnings found (continuing)${NC}"
}

# Step 6: Install @vscode/vsce locally (newer version without keytar)
echo ""
echo "📥 Installing @vscode/vsce locally..."
npm install --no-save @vscode/vsce
echo -e "${GREEN}✓ @vscode/vsce installed${NC}"

# Step 6.5: Auto-increment version
echo ""
echo "📈 Auto-incrementing version..."
# Get current version
CURRENT_VERSION=$(node -p "require('./package.json').version")
# Increment patch version
NEW_VERSION=$(echo $CURRENT_VERSION | awk -F. '{$NF = $NF + 1;} 1' | sed 's/ /./g')
# Update package.json
sed -i "s/\"version\": \"$CURRENT_VERSION\"/\"version\": \"$NEW_VERSION\"/" package.json
echo -e "${GREEN}✓ Version updated from $CURRENT_VERSION to $NEW_VERSION${NC}"

# Step 7: Package extension
echo ""
echo "📦 Packaging extension..."
npx @vscode/vsce package --no-dependencies --allow-missing-repository

# Find the generated .vsix file
VSIX_FILE=$(ls -t *.vsix 2>/dev/null | head -n1)

if [ -z "$VSIX_FILE" ]; then
    echo -e "${RED}✗ Failed to create .vsix package${NC}"
    exit 1
fi

# Rename to the versioned filename.
#
# vsce names its output <name>-<version>.vsix, i.e. rspade-framework-N.vsix from
# the package `name` field. We ship it as rspade-vscode-extension-N.vsix instead:
# the version stays in the filename so a build is identifiable on sight, and the
# stem matches the vendored jqhtml-vscode-extension-N.vsix beside it.
#
# Consumers glob rather than hardcode (install.sh), so the version moving does
# not strand them. Step 2 cleaned every
# *.vsix before the build, so exactly one exists here now.
TARGET_VSIX="rspade-vscode-extension-${NEW_VERSION}.vsix"
if [ "$VSIX_FILE" != "$TARGET_VSIX" ]; then
    mv "$VSIX_FILE" "$TARGET_VSIX"
    VSIX_FILE="$TARGET_VSIX"
fi

# Step 8: Clean up build artifacts (keep .vsix)
echo ""
echo "🧹 Cleaning up build artifacts..."
rm -rf node_modules/
rm -f package-lock.json
echo -e "${GREEN}✓ Build artifacts cleaned${NC}"

# Step 9: Success!
echo ""
echo -e "${GREEN}✅ Build completed successfully!${NC}"
echo ""
echo "📦 Package created: ${YELLOW}$VSIX_FILE${NC}"
echo "   Size: $(du -h "$VSIX_FILE" | cut -f1)"
echo "   Path: $(pwd)/$VSIX_FILE"
echo ""
echo "📝 To install on your host machine:"
echo "   1. The .vsix file is available at:"
echo "      ${YELLOW}/var/www/html/app/RSpade/resource/vscode_extension/$VSIX_FILE${NC}"
echo ""
echo "   2. In VS Code on your host:"
echo "      - Press Ctrl+Shift+X (Extensions)"
echo "      - Click '...' → 'Install from VSIX...'"
echo "      - Navigate to the file above"
echo ""
echo "   3. Or from command line on host:"
echo "      ${YELLOW}code --install-extension /path/to/$VSIX_FILE${NC}"
echo ""
echo "✨ Extension built in Docker container!"
