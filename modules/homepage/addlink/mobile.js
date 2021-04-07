var AddLinkMobileArticleTarget = require( './AddLinkMobileArticleTarget.js' ),
	addlinkClasses = require( 'ext.growthExperiments.AddLink' ),
	AiSuggestionsPlaceholderTool = require( './AiSuggestionsPlaceholderTool.js' );

ve.dm.modelRegistry.register( addlinkClasses.DMRecommendedLinkAnnotation );
ve.ce.annotationFactory.register( addlinkClasses.CERecommendedLinkAnnotation );
ve.dm.modelRegistry.register( addlinkClasses.DMRecommendedLinkErrorAnnotation );
ve.ce.annotationFactory.register( addlinkClasses.CERecommendedLinkErrorAnnotation );
ve.ui.contextItemFactory.register( addlinkClasses.RecommendedLinkContextItem );
ve.ui.windowFactory.register( addlinkClasses.RecommendedLinkRejectionDialog );
ve.ui.toolFactory.register( AiSuggestionsPlaceholderTool );

// Disable context items for non-recommended links
ve.ce.MWInternalLinkAnnotation.static.canBeActive = false;
ve.ui.contextItemFactory.unregister( 'link' );
ve.ui.contextItemFactory.unregister( 'link/internal' );
ve.ui.toolFactory.unregister( ve.ui.MWLinkInspectorTool );

// HACK: Override the registration of MobileArticleTarget for 'wikitext'
ve.init.mw.targetFactory.register( AddLinkMobileArticleTarget );
