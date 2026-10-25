/**
 * scripts/version-sync.cjs
 *
 * Script d'automatisation de synchronisation et de montée de version sémantique
 * pour le plugin WordPress EG Mail (sans dépendance externe).
 */

'use strict';

const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const pluginSlug = 'eg-mail';
const mainPhpFileName = `${pluginSlug}.php`;

// Helper : validation SemVer
const SEMVER_REGEX = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/;

function cleanVersion(ver) {
    if (!ver || typeof ver !== 'string') return null;
    let clean = ver.trim();
    if (clean.startsWith('v') || clean.startsWith('V')) {
        clean = clean.slice(1);
    }
    return SEMVER_REGEX.test(clean) ? clean : null;
}

// 1. Détection de la version cible
let targetVersion = null;
const cliArg = process.argv[2];

if (cliArg) {
    targetVersion = cleanVersion(cliArg);
    if (!targetVersion) {
        console.error(`\x1b[31m❌ Erreur : L'argument "${cliArg}" n'est pas une version SemVer valide (ex: 1.2.3, 2.0.0-beta.1).\x1b[0m`);
        process.exit(1);
    }
} else {
    // Lecture depuis package.json ou eg-mail.php
    const pkgPath = path.join(rootDir, 'package.json');
    if (fs.existsSync(pkgPath)) {
        try {
            const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
            targetVersion = cleanVersion(pkg.version);
        } catch (e) {
            // ignore
        }
    }

    if (!targetVersion) {
        const phpPath = path.join(rootDir, mainPhpFileName);
        if (fs.existsSync(phpPath)) {
            const phpContent = fs.readFileSync(phpPath, 'utf8');
            const match = phpContent.match(/Version:\s*([^\s\r\n]+)/);
            if (match) {
                targetVersion = cleanVersion(match[1]);
            }
        }
    }

    if (!targetVersion) {
        console.error(`\x1b[31m❌ Erreur : Impossible de déterminer la version cible (fournissez un argument ou vérifiez package.json/${mainPhpFileName}).\x1b[0m`);
        process.exit(1);
    }
}

console.log(`\n\x1b[36m🚀 Synchronisation de version SemVer vers : \x1b[1m${targetVersion}\x1b[0m\n`);

const results = {
    updated: [],
    unchanged: [],
    warnings: []
};

// 2. Mise à jour de package.json
const packageJsonPath = path.join(rootDir, 'package.json');
if (fs.existsSync(packageJsonPath)) {
    try {
        const raw = fs.readFileSync(packageJsonPath, 'utf8');
        const pkg = JSON.parse(raw);
        if (pkg.version !== targetVersion) {
            pkg.version = targetVersion;
            fs.writeFileSync(packageJsonPath, JSON.stringify(pkg, null, 2) + '\n', 'utf8');
            results.updated.push({
                file: 'package.json',
                details: `"version": "${targetVersion}"`
            });
        } else {
            results.unchanged.push({
                file: 'package.json',
                details: `Déjà à la version ${targetVersion}`
            });
        }
    } catch (err) {
        results.warnings.push(`package.json : Erreur de lecture/écriture (${err.message})`);
    }
} else {
    results.warnings.push('package.json introuvable');
}

// 3. Mise à jour du fichier PHP principal (eg-mail.php)
const phpFilePath = path.join(rootDir, mainPhpFileName);
if (fs.existsSync(phpFilePath)) {
    try {
        let content = fs.readFileSync(phpFilePath, 'utf8');
        let modified = false;

        // Header plugin: * Version: X.Y.Z
        const headerRegex = /^([ \t]*\*[ \t]*Version:[ \t]*)[^\r\n]*/m;
        if (headerRegex.test(content)) {
            const currentHeaderMatch = content.match(headerRegex)[0];
            const newHeader = currentHeaderMatch.replace(/^([ \t]*\*[ \t]*Version:[ \t]*)[^\r\n]*/, `$1${targetVersion}`);
            if (currentHeaderMatch !== newHeader) {
                content = content.replace(headerRegex, newHeader);
                modified = true;
            }
        }

        // Constante PHP: define( 'EG_MAIL_VERSION', 'X.Y.Z' );
        const defineRegex = /(define\(\s*['"][A-Za-z0-9_]*VERSION['"]\s*,\s*['"])([^'"]+)(['"]\s*\);)/g;
        if (defineRegex.test(content)) {
            const newContent = content.replace(defineRegex, `$1${targetVersion}$3`);
            if (newContent !== content) {
                content = newContent;
                modified = true;
            }
        }

        if (modified) {
            fs.writeFileSync(phpFilePath, content, 'utf8');
            results.updated.push({
                file: mainPhpFileName,
                details: `En-tête WP & Constante EG_MAIL_VERSION -> ${targetVersion}`
            });
        } else {
            results.unchanged.push({
                file: mainPhpFileName,
                details: `Déjà à jour (${targetVersion})`
            });
        }
    } catch (err) {
        results.warnings.push(`${mainPhpFileName} : Erreur (${err.message})`);
    }
} else {
    results.warnings.push(`${mainPhpFileName} introuvable`);
}

// 4. Blocs Gutenberg (block.json) récursifs
function findBlockJsonFiles(dir, fileList = []) {
    if (!fs.existsSync(dir)) return fileList;
    const entries = fs.readdirSync(dir, { withFileTypes: true });

    const ignoredDirs = new Set(['node_modules', 'vendor', '.git', 'dist-temp', 'build', '.dist']);

    for (const entry of entries) {
        const fullPath = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            if (!ignoredDirs.has(entry.name)) {
                findBlockJsonFiles(fullPath, fileList);
            }
        } else if (entry.isFile() && entry.name === 'block.json') {
            fileList.push(fullPath);
        }
    }
    return fileList;
}

const searchDirs = ['src', 'blocks', 'includes'].map(d => path.join(rootDir, d));
const blockFiles = [];
for (const sDir of searchDirs) {
    findBlockJsonFiles(sDir, blockFiles);
}

for (const blockFile of blockFiles) {
    const relPath = path.relative(rootDir, blockFile);
    try {
        const raw = fs.readFileSync(blockFile, 'utf8');
        const json = JSON.parse(raw);
        if (json.version !== targetVersion) {
            json.version = targetVersion;
            fs.writeFileSync(blockFile, JSON.stringify(json, null, 2) + '\n', 'utf8');
            results.updated.push({
                file: relPath,
                details: `"version": "${targetVersion}"`
            });
        } else {
            results.unchanged.push({
                file: relPath,
                details: `Déjà à jour (${targetVersion})`
            });
        }
    } catch (err) {
        results.warnings.push(`${relPath} : Erreur (${err.message})`);
    }
}

// 5. Mise à jour CHANGELOG.md (Keep a Changelog)
const changelogPath = path.join(rootDir, 'CHANGELOG.md');
if (fs.existsSync(changelogPath)) {
    try {
        let changelog = fs.readFileSync(changelogPath, 'utf8');

        // Formater la date du jour YYYY-MM-DD
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        const todayStr = `${yyyy}-${mm}-${dd}`;

        // Vérifier si la version cible est déjà présente
        const escapedVersion = targetVersion.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const versionAlreadyInChangelog = new RegExp(`^##\\s*\\[?${escapedVersion}\\]?`, 'm').test(changelog);

        if (versionAlreadyInChangelog) {
            results.unchanged.push({
                file: 'CHANGELOG.md',
                details: `Section [${targetVersion}] déjà présente`
            });
        } else {
            // Recherche de la section Unreleased
            const unreleasedRegex = /^(##\s*\[Unreleased\][^\r\n]*)/mi;
            if (unreleasedRegex.test(changelog)) {
                changelog = changelog.replace(unreleasedRegex, `## [Unreleased]\n\n## [${targetVersion}] - ${todayStr}`);
                fs.writeFileSync(changelogPath, changelog, 'utf8');
                results.updated.push({
                    file: 'CHANGELOG.md',
                    details: `Transformation de ## [Unreleased] en ## [${targetVersion}] - ${todayStr}`
                });
            } else {
                results.unchanged.push({
                    file: 'CHANGELOG.md',
                    details: `Aucune section ## [Unreleased] trouvée à convertir`
                });
            }
        }
    } catch (err) {
        results.warnings.push(`CHANGELOG.md : Erreur (${err.message})`);
    }
}

// 6. Mise à jour RELEASE.md (si présent)
const releasePath = path.join(rootDir, 'RELEASE.md');
if (fs.existsSync(releasePath)) {
    try {
        let releaseContent = fs.readFileSync(releasePath, 'utf8');
        let modified = false;

        // Titre: # Release Notes — EG Mail vX.Y.Z
        const titleRegex = /^(#\s*Release Notes\s*—\s*EG Mail\s*v)[^\r\n]*/m;
        if (titleRegex.test(releaseContent)) {
            const newTitle = releaseContent.match(titleRegex)[0].replace(/^(#\s*Release Notes\s*—\s*EG Mail\s*v)[^\r\n]*/, `$1${targetVersion}`);
            if (newTitle !== releaseContent.match(titleRegex)[0]) {
                releaseContent = releaseContent.replace(titleRegex, newTitle);
                modified = true;
            }
        }

        if (modified) {
            fs.writeFileSync(releasePath, releaseContent, 'utf8');
            results.updated.push({
                file: 'RELEASE.md',
                details: `Titre mis à jour vers v${targetVersion}`
            });
        } else {
            results.unchanged.push({
                file: 'RELEASE.md',
                details: `Déjà à jour (${targetVersion})`
            });
        }
    } catch (err) {
        results.warnings.push(`RELEASE.md : Erreur (${err.message})`);
    }
}

// 7. Affichage du récapitulatif
console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
console.log('\x1b[1m📊 RÉCAPITULATIF DE SYNCHRONISATION DES VERSIONS\x1b[0m');
console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n');

if (results.updated.length > 0) {
    console.log('\x1b[32m✔ Fichiers mis à jour :\x1b[0m');
    for (const item of results.updated) {
        console.log(`  \x1b[32m✔\x1b[0m \x1b[1m${item.file}\x1b[0m (${item.details})`);
    }
    console.log('');
}

if (results.unchanged.length > 0) {
    console.log('\x1b[34mℹ Fichiers déjà conformes :\x1b[0m');
    for (const item of results.unchanged) {
        console.log(`  \x1b[34m-\x1b[0m ${item.file} (${item.details})`);
    }
    console.log('');
}

if (results.warnings.length > 0) {
    console.log('\x1b[33m⚠️ Avertissements :\x1b[0m');
    for (const warn of results.warnings) {
        console.log(`  \x1b[33m!\x1b[0m ${warn}`);
    }
    console.log('');
}

console.log(`\x1b[32m✨ Synchronisation terminée avec succès pour la version \x1b[1m${targetVersion}\x1b[0m\n`);
process.exit(0);

