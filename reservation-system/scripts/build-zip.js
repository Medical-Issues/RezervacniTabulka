#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const archiver = require('archiver');
const { glob } = require('glob');
const chalk = require('chalk');

// Configuration
const PLUGIN_DIR = path.resolve(__dirname, '..');
const DIST_DIR = path.join(PLUGIN_DIR, 'dist');
const MAIN_FILE = path.join(PLUGIN_DIR, 'reservation-system.php');
const DISTIGNORE = path.join(PLUGIN_DIR, '.distignore');
const PLUGIN_SLUG = 'reservation-system';

/**
 * Read version from main plugin file
 */
function getPluginVersion() {
    try {
        const content = fs.readFileSync(MAIN_FILE, 'utf8');
        const versionMatch = content.match(/Version:\s*(.+)/i);
        if (versionMatch && versionMatch[1]) {
            return versionMatch[1].trim();
        }
    } catch (error) {
        console.error(chalk.red('Error reading main plugin file:'), error.message);
    }
    return '1.0.0';
}

/**
 * Parse .distignore file
 */
function parseDistignore() {
    const ignorePatterns = [
        'dist/**',
        'node_modules/**',
        'src/**',
        'scripts/**',
        '.git/**',
        '.serena/**',
        'package.json',
        'package-lock.json',
        '.distignore',
        'pnpm-lock.yaml'
    ];
    
    if (fs.existsSync(DISTIGNORE)) {
        const content = fs.readFileSync(DISTIGNORE, 'utf8');
        const lines = content.split('\n');
        
        for (const line of lines) {
            const trimmed = line.trim();
            if (trimmed && !trimmed.startsWith('#')) {
                let pattern = trimmed;
                if (pattern.startsWith('/')) {
                    pattern = pattern.substring(1);
                }
                ignorePatterns.push(pattern);
                if (pattern.endsWith('/')) {
                    ignorePatterns.push(pattern + '**');
                } else if (!pattern.includes('.') && !pattern.includes('*')) {
                    ignorePatterns.push(pattern + '/**');
                }
            }
        }
    }
    
    return ignorePatterns;
}

/**
 * Get all files to include in the ZIP
 */
async function getFilesToZip() {
    const ignorePatterns = parseDistignore();
    const allFiles = await glob('**/*', {
        cwd: PLUGIN_DIR,
        dot: true,
        nodir: true,
        ignore: ignorePatterns
    });
    return allFiles;
}

/**
 * Create the ZIP file
 */
async function createZip() {
    const version = getPluginVersion();
    const zipFileName = `${PLUGIN_SLUG}-${version}.zip`;
    const zipFilePath = path.join(DIST_DIR, zipFileName);
    
    console.log(chalk.blue('Building plugin ZIP...'));
    console.log(chalk.gray(`Version: ${version}`));
    console.log(chalk.gray(`Output: ${zipFileName}`));
    
    if (!fs.existsSync(DIST_DIR)) {
        fs.mkdirSync(DIST_DIR, { recursive: true });
    }
    
    const files = await getFilesToZip();
    console.log(chalk.gray(`Files to include: ${files.length}`));
    
    return new Promise((resolve, reject) => {
        const output = fs.createWriteStream(zipFilePath);
        const archive = archiver('zip', {
            zlib: { level: 9 }
        });
        
        output.on('close', () => {
            const sizeKB = (archive.pointer() / 1024).toFixed(2);
            console.log(chalk.green(`✓ ZIP created successfully!`));
            console.log(chalk.gray(`  Size: ${sizeKB} KB`));
            console.log(chalk.gray(`  Path: ${path.relative(process.cwd(), zipFilePath)}`));
            resolve();
        });
        
        archive.on('error', (err) => {
            console.error(chalk.red('Error creating ZIP:'), err);
            reject(err);
        });
        
        archive.pipe(output);
        
        files.forEach(file => {
            const filePath = path.join(PLUGIN_DIR, file);
            archive.file(filePath, { name: `${PLUGIN_SLUG}/${file}` });
        });
        
        archive.finalize();
    });
}

async function build() {
    console.log(chalk.bold('\n🚀 Building WordPress Plugin Package\n'));
    try {
        if (!fs.existsSync(MAIN_FILE)) {
            throw new Error('Main plugin file not found.');
        }
        await createZip();
        console.log(chalk.bold.green('\n✨ Build complete!\n'));
    } catch (error) {
        console.error(chalk.red('\n❌ Build failed:'), error.message);
        process.exit(1);
    }
}

build();
