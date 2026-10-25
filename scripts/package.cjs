/**
 * scripts/package.cjs
 *
 * Script d'automatisation de packaging pour le plugin WordPress EG Mail.
 * Valide la qualité (TypeScript, ESLint, PHPStan), compile les assets,
 * filtre les sources selon .distignore et génère l'archive ZIP autonome.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const os = require('os');
const { execSync } = require('child_process');
const AdmZip = require('adm-zip');
const { Minimatch } = require('minimatch');

const rootDir = path.resolve(__dirname, '..');
const pluginSlug = 'eg-mail';
const mainFile = path.join(rootDir, `${pluginSlug}.php`);
const distIgnorePath = path.join(rootDir, '.distignore');
const buildDir = path.join(rootDir, 'dist-temp');

console.log('🔍 Extraction de la version du plugin...');
if (!fs.existsSync(mainFile)) {
    console.error(`❌ Erreur : Fichier principal ${mainFile} introuvable.`);
    process.exit(1);
}

const mainContent = fs.readFileSync(mainFile, 'utf8');
const versionMatch = mainContent.match(/Version:\s*([^\s\r\n]+)/);
if (!versionMatch) {
    console.error(`❌ Erreur : Impossible de trouver la version dans ${mainFile}`);
    process.exit(1);
}
const version = versionMatch[1].trim();
console.log(`ℹ️  Version détectée : ${version}`);

const zipName = `${pluginSlug}-v${version}.zip`;
const genericZipName = `${pluginSlug}.zip`;
const tempDestDir = path.join(buildDir, pluginSlug);

// Fonction de résolution intelligente de PHP/Composer (notamment pour l'environnement LocalWP)
function resolvePhpEnvironment() {
    let hasGlobalPhp = false;
    try {
        execSync('php -v', { stdio: 'ignore' });
        hasGlobalPhp = true;
    } catch (e) {}

    let hasGlobalComposer = false;
    try {
        execSync('composer --version', { stdio: 'ignore' });
        hasGlobalComposer = true;
    } catch (e) {}

    const env = { ...process.env };
    if (!env.COMPOSER_HOME) {
        env.COMPOSER_HOME = path.join(os.tmpdir(), '.composer');
    }

    // 1. Si PHP et Composer sont tous deux globaux dans PATH
    if (hasGlobalPhp && hasGlobalComposer) {
        return {
            phpCmd: 'php',
            phpPrefix: 'php',
            composerCmd: 'composer',
            env,
            options: { shell: true }
        };
    }

    // 2. Recherche spécifique pour l'application "Local" sur Windows
    if (process.platform === 'win32') {
        const userProfile = process.env.USERPROFILE || process.env.HOMEPATH || '';
        const appData = process.env.APPDATA || path.join(userProfile, 'AppData/Roaming');
        const localAppData = process.env.LOCALAPPDATA || path.join(userProfile, 'AppData/Local');

        const localComposerPhar = path.join(localAppData, 'Programs/Local/resources/extraResources/bin/composer/composer.phar');
        const lightningServicesDir = path.join(appData, 'Local/lightning-services');
        let phpExePath = null;
        let phpExtPath = null;

        if (fs.existsSync(lightningServicesDir)) {
            const dirs = fs.readdirSync(lightningServicesDir);
            const phpDirs = dirs.filter(d => d.startsWith('php-')).sort().reverse();
            const php84Dir = phpDirs.find(d => d.startsWith('php-8.4'));
            const chosenPhpDir = php84Dir || phpDirs[0];

            if (chosenPhpDir) {
                const testPath = path.join(lightningServicesDir, chosenPhpDir, 'bin/win64/php.exe');
                const extPath = path.join(lightningServicesDir, chosenPhpDir, 'bin/win64/ext');
                if (fs.existsSync(testPath)) {
                    phpExePath = testPath;
                    if (fs.existsSync(extPath)) {
                        phpExtPath = extPath;
                    }
                }
            }
        }

        if (phpExePath) {
            console.log('💡 Environnement PHP LocalWP détecté !');
            console.log(`   - PHP : ${phpExePath}`);
            const phpDir = path.dirname(phpExePath);
            env.PATH = `${phpDir};${env.PATH || ''}`;

            let cliArgs = '';
            if (phpExtPath) {
                cliArgs = ` -d extension_dir="${phpExtPath}" -d extension=openssl -d extension=curl -d extension=mbstring`;
            }

            const phpPrefix = `"${phpExePath}"${cliArgs}`;
            let composerCmd = null;
            if (hasGlobalComposer) {
                composerCmd = 'composer';
            } else if (fs.existsSync(localComposerPhar)) {
                composerCmd = `${phpPrefix} "${localComposerPhar}"`;
                console.log(`   - Composer : ${localComposerPhar}`);
            }

            return {
                phpCmd: phpExePath,
                phpPrefix,
                composerCmd,
                env,
                options: { shell: true }
            };
        }
    }

    // 3. Recherche spécifique pour l'application "Local" sur macOS
    if (process.platform === 'darwin') {
        const homeDir = process.env.HOME || '';
        const localComposerPhar = '/Applications/Local.app/Contents/Resources/extraResources/bin/composer/composer.phar';
        const lightningServicesDir = path.join(homeDir, 'Library/Application Support/Local/lightning-services');
        let phpBinPath = null;

        if (fs.existsSync(lightningServicesDir)) {
            const dirs = fs.readdirSync(lightningServicesDir);
            const phpDirs = dirs.filter(d => d.startsWith('php-')).sort().reverse();
            const php84Dir = phpDirs.find(d => d.startsWith('php-8.4'));
            const chosenPhpDir = php84Dir || phpDirs[0];

            if (chosenPhpDir) {
                const possiblePaths = [
                    path.join(lightningServicesDir, chosenPhpDir, 'bin/sbin/php'),
                    path.join(lightningServicesDir, chosenPhpDir, 'bin/bin/php'),
                    path.join(lightningServicesDir, chosenPhpDir, 'bin/php')
                ];
                for (const p of possiblePaths) {
                    if (fs.existsSync(p)) {
                        phpBinPath = p;
                        break;
                    }
                }
            }
        }

        if (phpBinPath) {
            console.log('💡 Environnement PHP LocalWP détecté !');
            console.log(`   - PHP : ${phpBinPath}`);
            const phpDir = path.dirname(phpBinPath);
            env.PATH = `${phpDir}:${env.PATH || ''}`;

            const phpPrefix = `"${phpBinPath}"`;
            let composerCmd = null;
            if (hasGlobalComposer) {
                composerCmd = 'composer';
            } else if (fs.existsSync(localComposerPhar)) {
                composerCmd = `${phpPrefix} "${localComposerPhar}"`;
                console.log(`   - Composer : ${localComposerPhar}`);
            }

            return {
                phpCmd: phpBinPath,
                phpPrefix,
                composerCmd,
                env,
                options: { shell: true }
            };
        }
    }

    if (hasGlobalPhp) {
        return {
            phpCmd: 'php',
            phpPrefix: 'php',
            composerCmd: hasGlobalComposer ? 'composer' : null,
            env,
            options: { shell: true }
        };
    }

    return null;
}

const phpEnv = resolvePhpEnvironment();

console.log('🧪 Exécution de la suite de validation Qualité (QA)...');
try {
    console.log('  → Typecheck TypeScript (tsc)...');
    execSync('npm run typecheck', { cwd: rootDir, stdio: 'inherit' });
    console.log('  → Linting JavaScript/TypeScript (eslint)...');
    execSync('npm run lint', { cwd: rootDir, stdio: 'inherit' });
    console.log('  → Analyse statique PHPStan (Level 8)...');
    const phpstanBin = path.join(rootDir, 'vendor', 'bin', 'phpstan');
    if (!phpEnv || !phpEnv.phpPrefix) {
        throw new Error('PHP est introuvable sur le système pour exécuter PHPStan.');
    }
    execSync(`${phpEnv.phpPrefix} "${phpstanBin}" analyze --debug --memory-limit=2G`, {
        cwd: rootDir,
        stdio: 'inherit',
        env: phpEnv.env
    });
    console.log('✔ Contrôles Qualité (QA) validés avec succès.\n');
} catch (error) {
    console.error('❌ Erreur : Un contrôle QA a échoué. Packaging annulé.');
    process.exit(1);
}

console.log('🏗️  Compilation des assets locaux (Production)...');
try {
    execSync('npm run build', { cwd: rootDir, stdio: 'inherit' });
} catch (error) {
    console.error('❌ Erreur : Le build a échoué. Packaging annulé.');
    process.exit(1);
}

// Nettoyage préalable
if (fs.existsSync(path.join(rootDir, zipName))) {
    fs.unlinkSync(path.join(rootDir, zipName));
}
if (fs.existsSync(path.join(rootDir, genericZipName))) {
    fs.unlinkSync(path.join(rootDir, genericZipName));
}
if (fs.existsSync(buildDir)) {
    fs.rmSync(buildDir, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}
fs.mkdirSync(tempDestDir, { recursive: true });

// Chargement des règles .distignore
let ignoreRules = [];
if (fs.existsSync(distIgnorePath)) {
    fs.readFileSync(distIgnorePath, 'utf8')
        .split(/\r?\n/)
        .map(line => line.trim())
        .filter(line => line && !line.startsWith('#'))
        .forEach(pattern => {
            let matchPath = pattern;
            let isRootRelative = false;
            if (pattern.startsWith('/')) {
                matchPath = pattern.slice(1);
                isRootRelative = true;
            }

            // Si le motif se termine par '/', c'est un dossier.
            // On exclut le dossier lui-même et tout son contenu (comportement identique à gitignore / rsync)
            if (matchPath.endsWith('/')) {
                const dirPath = matchPath.slice(0, -1);
                ignoreRules.push({
                    mm: new Minimatch(dirPath, { dot: true, matchBase: !isRootRelative, nocomment: true })
                });
                ignoreRules.push({
                    mm: new Minimatch(dirPath + '/**', { dot: true, matchBase: !isRootRelative, nocomment: true })
                });
            } else {
                ignoreRules.push({
                    mm: new Minimatch(matchPath, { dot: true, matchBase: !isRootRelative, nocomment: true })
                });
                ignoreRules.push({
                    mm: new Minimatch(matchPath + '/**', { dot: true, matchBase: !isRootRelative, nocomment: true })
                });
            }
        });
}

function isIgnored(relPath) {
    const normalizedPath = relPath.replace(/\\/g, '/');
    if (!normalizedPath) return false;

    // Règles d'exclusion strictes : temporaires, archives, vendors et scripts d'outillage
    if (normalizedPath === 'dist-temp' || normalizedPath.startsWith('dist-temp/')) return true;
    if (normalizedPath.endsWith('.sh')) return true;
    if (normalizedPath.endsWith('.zip')) return true;
    if (normalizedPath === 'vendor' || normalizedPath.startsWith('vendor/')) return true;
    if (normalizedPath === 'script' || normalizedPath.startsWith('script/')) return true;
    if (normalizedPath === 'scripts' || normalizedPath.startsWith('scripts/')) return true;

    for (const rule of ignoreRules) {
        if (rule.mm.match(normalizedPath)) {
            return true;
        }
    }
    return false;
}

function copyFiltered(src, dest) {
    if (!fs.existsSync(src)) {
        return;
    }
    const stats = fs.statSync(src);
    const relPath = path.relative(rootDir, src);

    if (isIgnored(relPath)) {
        return;
    }

    if (stats.isDirectory()) {
        fs.mkdirSync(dest, { recursive: true });
        const entries = fs.readdirSync(src);
        for (const entry of entries) {
            copyFiltered(path.join(src, entry), path.join(dest, entry));
        }
    } else {
        const destDir = path.dirname(dest);
        if (!fs.existsSync(destDir)) {
            fs.mkdirSync(destDir, { recursive: true });
        }
        fs.copyFileSync(src, dest);
    }
}

console.log('📦 Préparation du répertoire temporaire et copie des fichiers...');
const rootEntries = fs.readdirSync(rootDir);
for (const entry of rootEntries) {
    copyFiltered(path.join(rootDir, entry), path.join(tempDestDir, entry));
}

// On s'assure d'avoir composer.json et composer.lock pour l'installation isolée si requise
const compJson = path.join(rootDir, 'composer.json');
const compLock = path.join(rootDir, 'composer.lock');
if (fs.existsSync(compJson)) {
    fs.copyFileSync(compJson, path.join(tempDestDir, 'composer.json'));
}
if (fs.existsSync(compLock)) {
    fs.copyFileSync(compLock, path.join(tempDestDir, 'composer.lock'));
}

console.log('📦 Installation isolée des dépendances Composer (Production)...');
if (!phpEnv || !phpEnv.composerCmd) {
    console.error("\n❌ Erreur : Composer est introuvable sur le système.");
    console.error("1. Assurez-vous que PHP et Composer sont installés localement et accessibles dans votre PATH.");
    console.error("2. Si vous utilisez l'application 'Local', lancez ce script de packaging directement depuis le 'Site Shell' de l'application (bouton 'Open Site Shell' dans Local).\n");
    fs.rmSync(buildDir, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
    process.exit(1);
}

try {
    // --ignore-platform-reqs permet d'éviter les erreurs de compatibilité si la version locale de PHP
    // diffère légèrement de celle attendue par le fichier composer.lock lors du packaging
    execSync(`${phpEnv.composerCmd} install --no-dev --optimize-autoloader --no-interaction --quiet --ignore-platform-reqs`, {
        cwd: tempDestDir,
        stdio: 'inherit',
        shell: phpEnv.options.shell,
        env: phpEnv.env
    });
} catch (error) {
    console.error("\n❌ Erreur : L'installation Composer a échoué dans le dossier temporaire.");
    fs.rmSync(buildDir, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
    process.exit(1);
}

console.log('🧹 Nettoyage des fichiers résiduels...');
const fpdfDir = path.join(tempDestDir, 'vendor/setasign/fpdf');
if (fs.existsSync(fpdfDir)) {
    fs.rmSync(path.join(fpdfDir, 'doc'), { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
    fs.rmSync(path.join(fpdfDir, 'tutorial'), { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
    ['FAQ.htm', 'changelog.htm', 'install.txt'].forEach(file => {
        const filePath = path.join(fpdfDir, file);
        if (fs.existsSync(filePath)) {
            fs.unlinkSync(filePath);
        }
    });
}

// Nettoyage de composer.json/lock dans la destination avant de zipper
const destCompJson = path.join(tempDestDir, 'composer.json');
const destCompLock = path.join(tempDestDir, 'composer.lock');
if (fs.existsSync(destCompJson)) fs.unlinkSync(destCompJson);
if (fs.existsSync(destCompLock)) fs.unlinkSync(destCompLock);

console.log('🤐 Création de l\'archive ZIP...');
try {
    const zip = new AdmZip();
    zip.addLocalFolder(tempDestDir, pluginSlug);
    zip.writeZip(path.join(rootDir, zipName));
    fs.copyFileSync(path.join(rootDir, zipName), path.join(rootDir, genericZipName));
} catch (error) {
    console.error('❌ Erreur lors de la création du ZIP :', error);
    fs.rmSync(buildDir, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
    process.exit(1);
}

// Nettoyage final
fs.rmSync(buildDir, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });

console.log(`✅ Package créé avec succès : ${zipName} & ${genericZipName} (Environnement local préservé)`);
