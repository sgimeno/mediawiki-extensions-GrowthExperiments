var RecommendedLinkToolbarDialog = require( 'ext.growthExperiments.AddLink' ).RecommendedLinkToolbarDialog;

/**
 * @class mw.libs.ge.RecommendedLinkToolbarDialogDesktop
 * @extends mw.libs.ge.RecommendedLinkToolbarDialog
 * @constructor
 */
function RecommendedLinkToolbarDialogDesktop() {
	RecommendedLinkToolbarDialogDesktop.super.apply( this, arguments );
	this.$element.addClass( [ 'mw-ge-recommendedLinkContextItem-desktop' ] );
}

OO.inheritClass( RecommendedLinkToolbarDialogDesktop, RecommendedLinkToolbarDialog );

RecommendedLinkToolbarDialogDesktop.static.size = 'full';
RecommendedLinkToolbarDialogDesktop.static.position = 'inline';

/**
 * @inheritdoc
 */
RecommendedLinkToolbarDialogDesktop.prototype.initialize = function () {
	RecommendedLinkToolbarDialogDesktop.super.prototype.initialize.call( this );
	this.$anchor = $( '<div>' ).addClass( 'mw-ge-recommendedLinkContextItem-desktop-anchor' );
	this.$element.prepend( this.$anchor );
	this.$element.detach();
};

/**
 * @inheritdoc
 */
RecommendedLinkToolbarDialogDesktop.prototype.afterSetupProcess = function () {
	RecommendedLinkToolbarDialogDesktop.super.prototype.afterSetupProcess.call( this );
	this.surface.getView().$element.append( this.$element );
	$( window ).on( 'resize',
		OO.ui.debounce( this.updatePosition.bind( this ), 250 )
	);
};

/**
 * @inheritdoc
 */
RecommendedLinkToolbarDialogDesktop.prototype.updateContentForCurrentRecommendation = function () {
	RecommendedLinkToolbarDialogDesktop.super.prototype.updateContentForCurrentRecommendation.call( this );
	this.updatePosition();
};

/**
 * Update the coordinates of the current window based on the location of the annotation
 * The window is aligned to the left edge of the annotation if it fits in the surface,
 * if not, it is aligned to the right edge.
 */
RecommendedLinkToolbarDialogDesktop.prototype.updatePosition = function () {
	var $surfaceView = this.surface.getView().$element,
		surfaceOffset = $surfaceView.offset(),
		maxSideEdge = $surfaceView.width(),
		$annotationView = this.annotationView.$element,
		annotationOffset = $annotationView.offset(),
		annotationWidth = $annotationView.outerWidth(),
		elementWidth = Math.max( this.$element.width(), 400 ),
		newPosition = {
			top: annotationOffset.top - surfaceOffset.top + 30
		},
		isRtl = this.surface.getDir() === 'rtl',
		positionName = isRtl ? 'right' : 'left',
		isStartAnchored = true,
		startPosition;

	if ( isRtl ) {
		startPosition = maxSideEdge - annotationOffset.left - annotationWidth;
	} else {
		startPosition = annotationOffset.left - surfaceOffset.left;
	}

	// Check whether the window will overflow past the surface edge
	if ( startPosition + elementWidth > maxSideEdge ) {
		newPosition[ positionName ] = startPosition - elementWidth + annotationWidth;
		isStartAnchored = false;
	} else {
		newPosition[ positionName ] = startPosition;
	}

	this.$element.css( newPosition );
	this.$anchor.toggleClass( 'mw-ge-recommendedLinkContextItem-desktop-anchor-start', isStartAnchored );
	this.$anchor.toggleClass( 'mw-ge-recommendedLinkContextItem-desktop-anchor-end', !isStartAnchored );
};

module.exports = RecommendedLinkToolbarDialogDesktop;