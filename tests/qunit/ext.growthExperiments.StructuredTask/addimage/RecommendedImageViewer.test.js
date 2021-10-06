'use strict';

const pathToWidget = '../../../../modules/ext.growthExperiments.StructuredTask/addimage/RecommendedImageViewer.js';

/**
 * @param {Object} overrides
 * @return { mw.libs.ge.RecommendedImageMetadata}
 */
const getMetadata = ( overrides = {} ) => {
	return {
		descriptionUrl: 'https://commons.wikimedia.org/wiki/File:HMS_Pandora.jpg',
		thumbUrl: 'https://upload.wikimedia.org/wikipedia/commons/thumb/3/3d/HMS_Pandora.jpg/300px-HMS_Pandora.jpg',
		fullUrl: 'https://upload.wikimedia.org/wikipedia/commons/3/3d/HMS_Pandora.jpg',
		originalWidth: 1024,
		originalHeight: 768,
		mustRender: true,
		isVectorized: false,
		...overrides
	};
};

/**
 * @param {number} size Thumbnail size
 * @return {string}
 */
const getThumbUrl = ( size ) => {
	return `https://upload.wikimedia.org/wikipedia/commons/thumb/3/3d/HMS_Pandora.jpg/${size}px-HMS_Pandora.jpg`;
};

QUnit.module( 'ext.growthExperiments.StructuredTask/addimage/RecommendedImageViewer.js', QUnit.newMwEnvironment( {
	setup() {
		mw.util.setOptionsForTest( { GenerateThumbnailOnParse: false } );
	}
} ) );

QUnit.test( 'getImageSrc: target width < original width', function ( assert ) {
	const viewport = {
		innerHeight: 629,
		innerWidth: 375,
		devicePixelRatio: 2
	};
	const RecommendedImageViewer = require( pathToWidget );
	const recommendedImageViewer = new RecommendedImageViewer();
	assert.deepEqual(
		recommendedImageViewer.getRenderData( getMetadata(), viewport ), {
			src: getThumbUrl( viewport.innerWidth * viewport.devicePixelRatio ),
			maxWidth: viewport.innerWidth
		}
	);
} );

QUnit.test( 'getImageSrc: the image file needs to be re-rasterized', function ( assert ) {
	const viewport = {
		innerHeight: 629,
		innerWidth: 375,
		devicePixelRatio: 2
	};
	const metadata = getMetadata( { mustRender: true, originalWidth: 750 } );
	const RecommendedImageViewer = require( pathToWidget );
	const recommendedImageViewer = new RecommendedImageViewer();
	assert.deepEqual(
		recommendedImageViewer.getRenderData( metadata, viewport ), {
			src: getThumbUrl( viewport.innerWidth * viewport.devicePixelRatio ),
			maxWidth: viewport.innerWidth
		}
	);
} );

QUnit.test( 'getImageSrc: vector image', function ( assert ) {
	const viewport = {
		innerHeight: 629,
		innerWidth: 375,
		devicePixelRatio: 2
	};
	const metadata = getMetadata( { isVectorized: true } );
	const RecommendedImageViewer = require( pathToWidget );
	const recommendedImageViewer = new RecommendedImageViewer();
	assert.deepEqual(
		recommendedImageViewer.getRenderData( metadata, viewport ), {
			src: getThumbUrl( viewport.innerWidth * viewport.devicePixelRatio ),
			maxWidth: viewport.innerWidth
		}
	);
} );

QUnit.test( 'getImageSrc: target width > original width', function ( assert ) {
	const viewport = {
		innerHeight: 629,
		innerWidth: 375,
		devicePixelRatio: 2
	};
	const metadata = getMetadata( { originalWidth: 700 } );
	const RecommendedImageViewer = require( pathToWidget );
	const recommendedImageViewer = new RecommendedImageViewer();
	assert.deepEqual(
		recommendedImageViewer.getRenderData( metadata, viewport ), {
			src: metadata.fullUrl,
			maxWidth: metadata.originalWidth
		}
	);
} );

QUnit.test( 'getImageSrc: target width > original width due to px ratio', function ( assert ) {
	const viewport = {
		innerHeight: 629,
		innerWidth: 375,
		devicePixelRatio: 3
	};
	const metadata = getMetadata();
	const RecommendedImageViewer = require( pathToWidget );
	const recommendedImageViewer = new RecommendedImageViewer();
	assert.deepEqual(
		recommendedImageViewer.getRenderData( metadata, viewport ), {
			src: metadata.fullUrl,
			maxWidth: metadata.originalWidth
		}
	);
} );

QUnit.test( 'getImageSrc: 3x target width', function ( assert ) {
	const viewport = {
		innerHeight: 629,
		innerWidth: 375,
		devicePixelRatio: 3
	};
	const metadata = getMetadata( { originalWidth: 5000 } );
	const RecommendedImageViewer = require( pathToWidget );
	const recommendedImageViewer = new RecommendedImageViewer();
	assert.deepEqual(
		recommendedImageViewer.getRenderData( metadata, viewport ), {
			src: getThumbUrl( viewport.devicePixelRatio * viewport.innerWidth ),
			maxWidth: viewport.innerWidth
		}
	);
} );

QUnit.test( 'getImageSrc: 2.5x target width', function ( assert ) {
	const viewport = {
		innerHeight: 629,
		innerWidth: 375,
		devicePixelRatio: 2.5
	};
	const metadata = getMetadata( { originalWidth: 5000 } );
	const RecommendedImageViewer = require( pathToWidget );
	const recommendedImageViewer = new RecommendedImageViewer();
	assert.deepEqual(
		recommendedImageViewer.getRenderData( metadata, viewport ), {
			src: getThumbUrl( Math.floor( viewport.devicePixelRatio * viewport.innerWidth ) ),
			maxWidth: viewport.innerWidth
		}
	);
} );

QUnit.test( 'getImageSrc: vertical image with landscape viewport', function ( assert ) {
	const viewport = {
		innerWidth: 629,
		innerHeight: 375,
		devicePixelRatio: 2
	};
	const metadata = getMetadata( { originalWidth: 768, originalHeight: 1024 } );
	const RecommendedImageViewer = require( pathToWidget );
	const recommendedImageViewer = new RecommendedImageViewer();
	const targetWidth = ( metadata.originalWidth / metadata.originalHeight ) * viewport.innerHeight;
	assert.deepEqual(
		recommendedImageViewer.getRenderData( metadata, viewport ), {
			src: getThumbUrl( Math.floor( targetWidth * viewport.devicePixelRatio ) ),
			maxWidth: Math.floor( targetWidth )
		}
	);
} );