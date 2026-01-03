#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const chalk = require('chalk');

const newVersion = process.argv[2] || JSON.parse(fs.readFileSync('./package.json', 'utf8')).version;

if (!newVersion) {
  console.error(chalk.red('Error: No version specified'));
  process.exit(1);
}

if (!/^\d+\.\d+\.\d+(-\w+)?$/.test(newVersion)) {
  console.error(chalk.red(`Error: Invalid version format: ${newVersion}`));
  process.exit(1);
}

console.log(chalk.blue(`Updating plugin version to ${newVersion}...`));

const filesToUpdate = [
  {
    path: './reservation-system.php',
    patterns: [
      { 
        search: /Version:\s*[\d.]+(-\w+)?/,
        replace: `Version: ${newVersion}`
      },
      {
        search: /define\(\s*'RS_VERSION',\s*'[\d.]+(-\w+)?'\s*\)/,
        replace: `define( 'RS_VERSION', '${newVersion}' )`
      }
    ]
  },
  {
    path: './package.json',
    patterns: [
      {
        search: /"version":\s*"[\d.]+(-\w+)?"/,
        replace: `"version": "${newVersion}"`
      }
    ]
  },
  {
    path: './README.md',
    patterns: [
      {
        search: /Stable tag:\s*[\d.]+(-\w+)?/,
        replace: `Stable tag: ${newVersion}`
      }
    ]
  }
];

filesToUpdate.forEach(({ path: filePath, patterns }) => {
  try {
    const fullPath = path.resolve(filePath);
    if (!fs.existsSync(fullPath)) return;

    let content = fs.readFileSync(fullPath, 'utf8');
    let updated = false;

    patterns.forEach(({ search, replace }) => {
      if (search.test(content)) {
        content = content.replace(search, replace);
        updated = true;
      }
    });

    if (updated) {
      fs.writeFileSync(fullPath, content);
      console.log(chalk.green(`✓ Updated ${filePath}`));
    }
  } catch (err) {
    console.error(chalk.red(`✗ Error updating ${filePath}:`), err.message);
  }
});

console.log(chalk.green(`\n✓ Plugin version updated to ${newVersion}`));
