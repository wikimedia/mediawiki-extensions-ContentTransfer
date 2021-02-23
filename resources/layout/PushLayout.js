( function( mw, $, d ) {
	contentTransfer.layout = {};

	contentTransfer.layout.Push = function( cfg ) {
		this.stages = {};
		this.currentStage = '';

		this.$element = $( '<div>' ).addClass( 'content-transfer-layout-push' );
		this.$stagesCnt = $( '<div>' ).addClass( 'content-transfer-layout-push-stages-cnt' );
		this.$pagesContainer = $( '<div>' ).addClass( 'content-transfer-layout-push-pages-cnt' );

		this.$element.append( this.$stagesCnt, this.$pagesContainer );
	};

	OO.initClass( contentTransfer.layout.Push );

	contentTransfer.layout.Push.prototype.addStage = function( cfg ) {
		var button = new OO.ui.ButtonWidget( {
			framed: false,
			label: cfg.label,
			icon: 'none'
		} );

		var page = $( '<div>' )
			.addClass( 'content-transfer-layout-push-page' )
			.append( cfg.content );

		this.stages[cfg.name] = {
			button: button,
			page: page
		};

		this.$stagesCnt.append( button.$element );
		this.$pagesContainer.append( page );
	}

	contentTransfer.layout.Push.prototype.setCurrentStage = function( stage ) {
		if( stage in this.stages == false ) {
			return;
		}
		if( this.currentStage ) {
			this.stages[ this.currentStage ].button.$element.removeClass( 'current-stage' );
		}
		this.currentStage = stage;
		var stage = this.stages[ stage ];

		stage.button.setIcon( 'next' );
		stage.button.$element.addClass( 'current-stage' );

		stage.page.css( 'display', 'block' );
	}

	contentTransfer.layout.Push.prototype.firstStage = function() {
		for( var stageName in this.stages ) {
			return this.setCurrentStage( stageName );
		}
	}

	contentTransfer.layout.Push.prototype.nextStage = function() {
		var currentStage = this.stages[ this.currentStage ];
		currentStage.button.setIcon( 'check' );
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
