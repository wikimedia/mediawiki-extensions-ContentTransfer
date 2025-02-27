( function( mw, $, d ) {
	contentTransfer.layout = {};

	contentTransfer.layout.Push = function( cfg ) {
		this.stages = {};
		this.currentStage = '';

		this.$element = $( '<div>' ).addClass( 'content-transfer-layout-push' );
		this.$pagesContainer = $( '<div>' ).addClass( 'content-transfer-layout-push-pages-cnt' );
		this.$element.append( this.$pagesContainer );
	};

	OO.initClass( contentTransfer.layout.Push );

	contentTransfer.layout.Push.prototype.addStage = function( cfg ) {
		var page = $( '<div>' )
			.addClass( 'content-transfer-layout-push-page' )
			.append( cfg.content );

		this.stages[cfg.name] = {
			page: page
		};
		this.$pagesContainer.append( page );
	}

	contentTransfer.layout.Push.prototype.setCurrentStage = function( stage ) {
		if( stage in this.stages == false ) {
			return;
		}

		this.currentStage = stage;
		var stage = this.stages[ stage ];

		stage.page.css( 'display', 'block' );
	}

	contentTransfer.layout.Push.prototype.firstStage = function() {
		for( var stageName in this.stages ) {
			return this.setCurrentStage( stageName );
		}
	}

	contentTransfer.layout.Push.prototype.nextStage = function() {
		var currentStage = this.stages[ this.currentStage ];
		currentStage.page.hide();

		var isNext = false;
		for( var stageName in this.stages ) {
			if( isNext ) {
				return this.setCurrentStage( stageName );
			}
			if( stageName === this.currentStage ) {
				isNext = true;
			}
		}
	}

} )( mediaWiki, jQuery, document );
