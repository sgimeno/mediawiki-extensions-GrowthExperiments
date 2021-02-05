var AddLinkDesktopArticleTarget = require( './AddLinkDesktopArticleTarget.js' ),
	addlinkClasses = require( 'ext.growthExperiments.AddLink' );

ve.dm.modelRegistry.register( addlinkClasses.DMRecommendedLinkAnnotation );
ve.ce.annotationFactory.register( addlinkClasses.CERecommendedLinkAnnotation );
ve.dm.modelRegistry.register( addlinkClasses.DMRecommendedLinkErrorAnnotation );
ve.ce.annotationFactory.register( addlinkClasses.CERecommendedLinkErrorAnnotation );
ve.ui.contextItemFactory.register( addlinkClasses.RecommendedLinkContextItem );
ve.ui.windowFactory.register( addlinkClasses.RecommendedLinkRejectionDialog );

// HACK: Override the registration of DesktopArticleTarget for 'wikitext'
ve.init.mw.targetFactory.register( AddLinkDesktopArticleTarget );