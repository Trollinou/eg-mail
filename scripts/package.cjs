/**
 * Script de packaging autonome générant l'archive zip de production conforme à .distignore.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const archiver = require( 'archiver' );

const pluginSlug = 'eg-mail';
const zipName = `${ pluginSlug }.zip`;
const rootDir = path.resolve( __dirname, '..' );
const outputPath = path.resolve( rootDir, zipName );

const distIgnorePath = path.resolve( rootDir, '.distignore' );
const ignorePatterns = fs.existsSync( distIgnorePath )
	? fs
			.readFileSync( distIgnorePath, 'utf-8' )
			.split( '\n' )
			.map( ( line ) => line.trim() )
			.filter( ( line ) => line.length > 0 && ! line.startsWith( '#' ) )
	: [];

const output = fs.createWriteStream( outputPath );
const archive = archiver( 'zip', { zlib: { level: 9 } } );

output.on( 'close', () => {
	console.log( `Archive générée : ${ zipName } (${ archive.pointer() } octets)` );
} );

archive.on( 'error', ( err ) => {
	throw err;
} );

archive.pipe( output );

archive.glob( '**/*', {
	cwd: rootDir,
	ignore: [ zipName, ...ignorePatterns, ...ignorePatterns.map( ( p ) => `${ p }/**` ) ],
	prefix: pluginSlug,
} );

archive.finalize();

