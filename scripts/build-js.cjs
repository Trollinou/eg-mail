/**
 * Script de compilation des bundles JavaScript / TypeScript via esbuild.
 */
const esbuild = require( 'esbuild' );
const fs = require( 'fs' );
const path = require( 'path' );

const srcDir = path.resolve( __dirname, '../src/ts' );
const outDir = path.resolve( __dirname, '../assets/js' );

if ( ! fs.existsSync( outDir ) ) {
	fs.mkdirSync( outDir, { recursive: true } );
}

if ( ! fs.existsSync( srcDir ) ) {
	console.log( 'Aucun dossier src/ts trouvé, étape ignorée.' );
	process.exit( 0 );
}

const entryPoints = fs
	.readdirSync( srcDir )
	.filter( ( file ) => file.endsWith( '.ts' ) || file.endsWith( '.js' ) )
	.map( ( file ) => path.join( srcDir, file ) );

if ( entryPoints.length === 0 ) {
	console.log( 'Aucun point d\'entrée TS/JS à compiler.' );
	process.exit( 0 );
}

esbuild
	.build( {
		entryPoints,
		outdir: outDir,
		bundle: true,
		minify: true,
		sourcemap: true,
		target: [ 'es2022' ],
		format: 'iife',
	} )
	.then( () => {
		console.log( 'Compilation JavaScript/TypeScript terminée avec succès.' );
	} )
	.catch( ( err ) => {
		console.error( err );
		process.exit( 1 );
	} );
