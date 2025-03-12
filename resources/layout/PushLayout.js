( function ( $ ) {
	contentTransfer.layout = {};

	contentTransfer.layout.Push = function () {
		this.stages = {};
		this.currentStage = '';

		this.$element = $( '<div>' ).addClass( 'content-transfer-layout-push' );
		this.$pagesContainer = $( '<div>' ).addClass( 'content-transfer-layout-push-pages-cnt' );
		this.$element.append( this.$pagesContainer );
	};

	OO.initClass( contentTransfer.layout.Push );

	contentTransfer.layout.Push.prototype.addStage = function ( cfg ) {
		const page = $( '<div>' ) // eslint-disable-line no-jquery/variable-pattern
			.addClass( 'content-transfer-layout-push-page' )
			.append( cfg.content );

		this.stages[ cfg.name ] = {
			page: page
		};
		this.$pagesContainer.append( page );
	};

	contentTransfer.layout.Push.prototype.setCurrentStage = function ( stage ) {
		if ( stage in this.stages == false ) { // eslint-disable-line eqeqeq
			return;
		}

		this.currentStage = stage;
		stage = this.stages[ stage ];

		stage.page.css( 'display', 'block' );
	};

	contentTransfer.layout.Push.prototype.firstStage = function () {
		for ( const stageName in this.stages ) { // eslint-disable-line no-unreachable-loop
			return this.setCurrentStage( stageName );
		}
	};

	contentTransfer.layout.Push.prototype.nextStage = function () {
		const currentStage = this.stages[ this.currentStage ];
		currentStage.page.hide();

		let isNext = false;
		for ( const stageName in this.stages ) {
			if ( isNext ) {
				return this.setCurrentStage( stageName );
			}
			if ( stageName === this.currentStage ) {
				isNext = true;
			}
		}
	};

}( jQuery ) );
