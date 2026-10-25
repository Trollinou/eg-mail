/**
 * Script de compilation des feuilles de styles SCSS via Dart Sass et RTLCSS.
 */
const sass = require( 'sass' );
const rtlcss = require( 'rtlcss' );
const fs = require( 'fs' );
const path = require( 'path' );

const srcDir = path.resolve( __dirname, '../src/scss' );
const outDir = path.resolve( __dirname, '../assets/css' );

if ( ! fs.existsSync( outDir ) ) {
	fs.mkdirSync( outDir, { recursive: true } );
}

if ( ! fs.existsSync( srcDir ) ) {
	console.log( 'Aucun dossier src/scss trouvé, étape ignorée.' );
	process.exit( 0 );
}

const scssFiles = fs
	.readdirSync( srcDir )
	.filter( ( file ) => file.endsWith( '.scss' ) && ! file.startsWith( '_' ) );

if ( scssFiles.length === 0 ) {
	console.log( 'Aucune feuille SCSS à compiler.' );
	process.exit( 0 );
}

for ( const file of scssFiles ) {
	const srcPath = path.join( srcDir, file );
	const baseName = file.replace( /\.scss$/, '' );
	const destLtr = path.join( outDir, `${ baseName }.css` );
	const destRtl = path.join( outDir, `${ baseName }-rtl.css` );

	try {
		const result = sass.compile( srcPath, {
			style: 'compressed',
			sourceMap: true,
		} );

		fs.writeFileSync( destLtr, result.css );

		// Génération de la variante RTL
		const rtlResult = rtlcss.process( result.css );
		fs.writeFileSync( destRtl, rtlResult );

		console.log( `Compilé : ${ baseName }.css et ${ baseName }-rtl.css` );
	} catch ( err ) {
		console.error( `Erreur lors de la compilation de ${ file }:`, err );
		process.exit( 1 );
	}
}
