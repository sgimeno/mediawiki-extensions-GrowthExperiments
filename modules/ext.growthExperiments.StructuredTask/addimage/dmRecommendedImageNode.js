/**
 * @class mw.libs.ge.dm.RecommendedImageNode
 * @extends ve.dm.MWBlockImageNode
 * @constructor
 */
function DMRecommendedImageNode() {
	DMRecommendedImageNode.super.apply( this, arguments );
}

OO.inheritClass( DMRecommendedImageNode, ve.dm.MWBlockImageNode );

DMRecommendedImageNode.static.name = 'mwGeRecommendedImage';
DMRecommendedImageNode.static.childNodeTypes = [ 'mwGeRecommendedImageCaption' ];

/** @inheritDoc **/
DMRecommendedImageNode.static.matchFunction = function ( element ) {
	// DMRecommendedImageNode inherits matchTagNames from ve.dm.MWBlockImageNode so figure elements
	// already in the article will be a match candidate. Additional class name check ensures that
	// existing images in the article don't get treated as a suggested image.
	var hasImage = ve.dm.BlockImageNode.static.matchFunction( element );
	return hasImage && element.classList.indexOf( 'mw-ge-recommendedImage' ) !== -1;
};

module.exports = DMRecommendedImageNode;
