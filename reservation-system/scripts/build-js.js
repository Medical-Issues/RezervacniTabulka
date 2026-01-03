const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { globSync } = require('glob');
const chalk = require('chalk');

const srcJsDir = path.resolve(__dirname, '../src/assets/js');
const distJsDir = path.resolve(__dirname, '../assets/js');

console.log(chalk.blue('Building JavaScript assets...'));

if (!fs.existsSync(srcJsDir)) {
    console.log(chalk.yellow('Source JS directory does not exist, skipping...'));
    process.exit(0);
}

if (!fs.existsSync(distJsDir)) {
    fs.mkdirSync(distJsDir, { recursive: true });
}

const jsFiles = globSync('**/*.js', { cwd: srcJsDir });

if (jsFiles.length === 0) {
    console.log(chalk.yellow('No JS files found in src/assets/js, skipping...'));
    process.exit(0);
}

jsFiles.forEach(file => {
    const srcPath = path.join(srcJsDir, file);
    const distPath = path.join(distJsDir, file);
    const minDistPath = distPath.replace(/\.js$/, '.min.js');
    
    // Ensure subdirectory exists in dist
    const subDir = path.dirname(distPath);
    if (!fs.existsSync(subDir)) {
        fs.mkdirSync(subDir, { recursive: true });
    }

    console.log(chalk.gray(`Processing ${file}...`));
    
    // Copy original
    fs.copyFileSync(srcPath, distPath);
    
    // Minify
    try {
        execSync(`npx terser "${srcPath}" -o "${minDistPath}" --compress --mangle`);
        console.log(chalk.green(`✓ Minified ${file}`));
    } catch (error) {
        console.error(chalk.red(`✗ Failed to minify ${file}:`), error.message);
    }
});

console.log(chalk.bold.green('JS build complete!'));
