( function ( mw, $ ) {
	window.contentTransfer = window.contentTransfer || {};
	window.contentTransfer.widget = window.contentTransfer.widget || {};

	contentTransfer.widget.PageSelectorWidget = function ( cfg ) {
		this.$element = cfg.$element;
		this.filters = cfg.filters;

		this.api = new mw.Api( {
			ajax: {
				timeout: 300 * 1000 // 5 min
			}
		} );

		this.makeSaveContainer();
		this.makeTargetContainer();
		this.makePageContainer();
		mw.hook( 'ext.contenttransfer.pageselector.init' ).fire( this );

		this.pushTargetPicker.getMenu().selectItem(
			this.pushTargetPicker.getMenu().findFirstSelectableItem()
		);

		this.loadPages();
	};

	OO.initClass( contentTransfer.widget.PageSelectorWidget );

	contentTransfer.widget.PageSelectorWidget.prototype.makeSaveContainer = function () {
		this.includeRelated = new OO.ui.CheckboxInputWidget( { selected: true } );
		this.pushPagesButton = new OO.ui.ButtonWidget( {
			label: mw.message( 'contenttransfer-push-pages-button-label' ).plain(),
			disabled: true,
			flags: [
				'primary',
				'progressive'
			]
		} );
		this.pushPagesButton.connect( this, { click: 'pushPages' } );
		this.pushTargetLayout = new OO.ui.HorizontalLayout( {
			items: [
				new OO.ui.FieldLayout( this.includeRelated, {
					align: 'inline',
					label: mw.message( 'contenttransfer-include-related' ).plain(),
					classes: [ 'related' ]
				} ),
				this.pushPagesButton
			]
		} );
		this.pushTargetLayout.$element.addClass( 'content-transfer-push-cnt' );
		this.$element.append( this.pushTargetLayout.$element );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.makeTargetContainer = function () {
		this.$element.append( $( '<h2>' ).html( mw.message( 'contenttransfer-transfer-wiki-heading-label' ).text() ) );
		this.makeTargetPicker();
	};

	contentTransfer.widget.PageSelectorWidget.prototype.makeTargetPicker = function () {
		this.pushTargets = mw.config.get( 'ctPushTargets' );
		const pickerOptions = [];
		for ( const key in this.pushTargets ) {
			if ( !this.pushTargets.hasOwnProperty( key ) ) {
				continue;
			}
			const pushTarget = this.pushTargets[ key ];
			pickerOptions.push( new OO.ui.MenuOptionWidget( {
				data: Object.assign( {}, { key: key }, pushTarget ),
				label: pushTarget.displayText || pushTarget.url
			} ) );
		}

		this.userSelector = new OO.ui.DropdownInputWidget();
		this.userSelector.connect( this, {
			change: 'onPushUserChanged'
		} );
		this.userSelectorLayout = new OO.ui.FieldLayout( this.userSelector, {
			align: 'top',
			label: mw.message( 'contenttransfer-user-picker-label' ).plain(),
			classes: [ 'picker' ]
		} );
		this.userSelectorLayout.$element.hide();

		this.pushTargetPicker = new OO.ui.DropdownWidget( {
			menu: {
				items: pickerOptions
			}
		} );
		this.pushTargetPicker.$element.addClass( 'content-transfer-push-target-picker' );
		this.pushTargetPicker.getMenu().connect( this, {
			select: 'onPushTargetChanged'
		} );

		this.pickerLayout = new OO.ui.HorizontalLayout( {
			items: [
				new OO.ui.FieldLayout( this.pushTargetPicker, {
					align: 'top',
					label: mw.message( 'contenttransfer-target-picker-label' ).plain(),
					classes: [ 'picker' ]
				} ),
				this.userSelectorLayout
			]
		} );
		this.pickerLayout.$element.addClass( 'content-transfer-targets-picker-layout' );
		this.$element.append( this.pickerLayout.$element );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.makeFilters = function () {
		this.toggleFilterButton = new OO.ui.ButtonWidget( {
			icon: this.expanded ? 'collapse' : 'expand',
			framed: false,
			classes: [ 'toggle-icon', 'collapsed-panel' ],
			title: this.expanded ?
				mw.message( 'contenttransfer-filter-toggle-btn-hide' ).text() :
				mw.message( 'contenttransfer-filter-toggle-btn-show' ).text()
		} );

		this.toggleFilterButton.connect( this, {
			click: 'onToggleFilter'
		} );

		this.filterHeaderLine = new OO.ui.HorizontalLayout( {
			items: [
				new OO.ui.LabelWidget( {
					label: mw.message( 'contenttransfer-filter-panel-label' ).text()
				} ),
				this.toggleFilterButton
			],
			classes: [ 'content-transfer-pages-filter-header' ]
		} );
		this.$element.append( this.filterHeaderLine.$element );
		this.filterPanel = new OO.ui.PanelLayout( {
			expanded: false
		} );
		if ( !this.expanded ) {
			this.filterPanel.$element.hide();
		}

		// Built-in filters
		this.onlyModified = new OO.ui.CheckboxInputWidget( {
			selected: true
		} );

		this.modifiedSince = new OO.ui.TextInputWidget( {
			placeholder: mw.message( 'contenttransfer-modified-since-ph' ).text(),
			validate: /^\s*(3[01]|[12][0-9]|0?[1-9])\.(1[012]|0?[1-9])\.((?:19|20)\d{2})\s*$/gm
		} );

		this.onlyModified.connect( this, { change: 'loadPages' } );
		this.modifiedSince.connect( this, {
			change: function () {
				this.modifiedSince.getValidity().done( () => {
					this.loadPages();
				} );
			}
		} );

		const onlyModifiedLayout = new OO.ui.FieldLayout( this.onlyModified, {
			align: 'inline',
			label: mw.message( 'contenttransfer-only-modified-label' ).text(),
			help: mw.message( 'contenttransfer-only-modified-help' ).text(),
			classes: [ 'only-modified-layout' ]
		} );
		const modifiedSinceLayout = new OO.ui.FieldLayout( this.modifiedSince, {
			align: 'top',
			label: mw.message( 'contenttransfer-modified-since-label' ).text()
		} );

		// Plugin filters
		this.filterInstances = {};
		const layouts = [];
		for ( const name in this.filters ) {
			if ( !this.filters.hasOwnProperty( name ) ) {
				continue;
			}

			const filter = this.filters[ name ],
				widgetClass = this.stringToCallback( filter.widgetClass ),
				widget = new widgetClass( Object.assign( {}, true, { // eslint-disable-line new-cap
					id: filter.id
				}, filter.widgetData || {} ) ),
				layout = new OO.ui.FieldLayout( widget, {
					align: 'top',
					label: filter.displayName
				} );
			widget.connect( this, { change: 'loadPages' } );
			layouts.push( layout );
			this.filterInstances[ name ] = widget;
		}

		const $filterLayout = $( '<div>' ).addClass( 'contenttransfer-filter-layout' );
		$filterLayout.append(
			new OO.ui.HorizontalLayout( { classes: [ 'bottom-margin table-50' ], items: [
				modifiedSinceLayout,
				onlyModifiedLayout
			] } ).$element,
			new OO.ui.HorizontalLayout( { items: layouts } ).$element
		);

		this.filterPanel.$element.append( $filterLayout );
		this.$element.append( this.filterPanel.$element );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.onToggleFilter = function () {
		if ( !this.expanded ) {
			// eslint-disable-next-line no-jquery/no-slide
			this.filterPanel.$element.slideDown( 300, () => {
				this.toggleFilterButton.setIcon( 'collapse' );
				this.toggleFilterButton.setTitle( mw.message( 'contenttransfer-filter-toggle-btn-hide' ).text() );
				this.expanded = true;
			} );
		} else {
			// eslint-disable-next-line no-jquery/no-slide
			this.filterPanel.$element.slideUp( 300, () => {
				this.toggleFilterButton.setIcon( 'expand' );
				this.toggleFilterButton.setTitle( mw.message( 'contenttransfer-filter-toggle-btn-show' ).text() );
				this.expanded = false;
			} );
		}
	};

	contentTransfer.widget.PageSelectorWidget.prototype.makePageContainer = function () {
		this.$pagesHeader = $( '<div>' ).addClass( 'content-transfer-pages-header' );
		this.$element.append( this.$pagesHeader );
		const headerLayout = new OO.ui.HorizontalLayout( {
			classes: [ 'header-layout' ]
		} );

		const $selectionCnt = $( '<div>' ).addClass( 'selection-cnt' );
		this.selectAllButton = new OO.ui.ToggleButtonWidget( {
			label: mw.message( 'contenttransfer-selection-toggle-btn-label' ).text(),
			classes: [ 'content-transfer-toggle-btn' ]
		} );
		this.selectAllButton.connect( this, { click: 'selectToggle' } );
		$selectionCnt.append( this.selectAllButton.$element );
		this.$pagesCount = $( '<div>' ).addClass( 'selected-count' );
		$selectionCnt.append( this.$pagesCount );

		headerLayout.$element.append(
			$( '<h2>' ).html( mw.message( 'contenttransfer-pages-header' ).plain() ),
			$selectionCnt
		);

		this.$pagesHeader.append( headerLayout.$element );

		this.makeFilters();
		this.$pageContainer = $( '<div>' ).addClass( 'content-transfer-page-container' );
		this.$element.append( this.$pageContainer );
		this.updateSelectedCount();
	};

	contentTransfer.widget.PageSelectorWidget.prototype.pushPages = function () {
		if ( !this.currentPushTarget ) {
			return;
		}

		const selectedPageIds = [];
		for ( const pageId in this.displayedPages ) {
			if ( this.displayedPages[ pageId ].selected ) {
				selectedPageIds.push( pageId );
			}
		}

		this.setPushWaitingNotice( true );
		this.api.get( {
			action: 'content-transfer-push-info',
			titles: JSON.stringify( selectedPageIds ),
			onlyModified: this.onlyModified.isSelected() ? 1 : 0,
			modifiedSince: this.modifiedSince.getValue(),
			includeRelated: this.includeRelated.isSelected() ? 1 : 0,
			target: this.currentPushTarget
		} )
			.done( ( response ) => {
				const windowManager = OO.ui.getWindowManager();
				const cfg = {
					grouped: response.grouped,
					joined: response.joined,
					pushTarget: Object.assign( {
						id: this.currentPushTarget,
						selectedUser: this.currentPushUser
					}, this.pushTargets[ this.currentPushTarget ] ),
					originalIds: selectedPageIds
				};

				const dialog = new contentTransfer.dialog.Push( cfg );
				windowManager.addWindows( [ dialog ] );
				windowManager.openWindow( dialog );
				this.setPushWaitingNotice( false );
			} );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.onPushTargetChanged = function ( selected ) {
		if ( !selected ) {
			this.currentPushTarget = null;
			this.currentPushUser = null;
			return;
		}
		const data = selected.getData(),
			users = data.users || [];

		this.currentPushTarget = data.key;
		this.loadPages();
		if ( users.length === 0 ) {
			return;
		}
		this.currentPushUser = users[ 0 ];
		if ( users.length === 1 ) {
			this.userSelectorLayout.$element.hide();
			return;
		}
		const options = [];
		for ( let i = 0; i < users.length; i++ ) {
			options.push( {
				data: users[ i ]
			} );
		}
		this.userSelector.setOptions( options );
		this.userSelectorLayout.$element.show();
		this.userSelector.setValue( this.currentPushUser );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.onPushUserChanged = function ( value ) {
		this.currentPushUser = value;
	};

	contentTransfer.widget.PageSelectorWidget.prototype.getComboValue = function ( value, type, key ) {
		if ( !value ) {
			return false;
		}
		key = key || 'id';
		for ( let i = 0; i < this.filterData[ type ].length; i++ ) {
			if ( this.filterData[ type ][ i ].text === value ) {
				value = this.filterData[ type ][ i ][ key ];
				if ( key === 'id' ) {
					return parseInt( value );
				}
				return value;
			}
		}

		return null;
	};

	contentTransfer.widget.PageSelectorWidget.prototype.getFilterData = function () {
		const values = {};
		// Plugin filters

		for ( const name in this.filterInstances ) {
			if ( !this.filterInstances.hasOwnProperty( name ) ) {
				continue;
			}
			const filterInstance = this.filterInstances[ name ];
			values[ filterInstance.$element.attr( 'id' ) ] = filterInstance.getValue();
		}
		// Build-in
		values.modifiedSince = this.modifiedSince.getValue();
		values.onlyModified = this.onlyModified.isSelected();

		return Object.assign( values, {
			target: this.currentPushTarget
		} );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.loadPages = function () {
		if ( !this.currentPushTarget ) {
			return;
		}
		const filterData = this.getFilterData();
		if ( !filterData ) {
			return;
		}

		this.clearPages();
		this.setPagesLoading( true );

		this.api.abort();
		this.api.get( {
			action: 'content-transfer-get-pages',
			filterData: JSON.stringify( filterData )
		} )
			.done( ( response ) => {
				this.setPagesLoading( false );
				this.displayPages( response );
			} );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.clearPages = function () {
		this.$pageContainer.children().remove();
		this.displayedPages = {};
		this.updateSelectedCount();
	};

	contentTransfer.widget.PageSelectorWidget.prototype.setPagesLoading = function ( loading ) {
		loading = loading || false;
		if ( !loading && this.$pageContainer.find( '.page-loader' ).length > 0 ) {
			this.$pageContainer.find( '.page-loader' ).remove();
		} else {
			if ( this.$pageContainer.find( '.page-loader' ).length > 0 ) {
				return;
			}
			this.$pageContainer.append( new OO.ui.ProgressBarWidget( {
				classes: [ 'page-loader' ]
			} ).$element );
		}
	};

	contentTransfer.widget.PageSelectorWidget.prototype.setTooManyPagesWarning = function ( total, retrieved ) {
		this.$pageContainer.prepend( new OO.ui.MessageWidget( {
			type: 'warning',
			label: mw.message( 'contenttransfer-too-many-pages-warning', total, retrieved ).text()
		} ).$element );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.updateSelectedCount = function () {
		let total = 0,
			selected = 0;

		for ( const pageId in this.displayedPages ) {
			if ( !this.displayedPages.hasOwnProperty( pageId ) ) {
				continue;
			}
			total++;
			if ( this.displayedPages[ pageId ].selected ) {
				selected++;
			}
		}

		if ( selected > 0 ) {
			this.pushPagesButton.setDisabled( false );
		} else {
			this.pushPagesButton.setDisabled( true );
		}

		if ( total === selected ) {
			this.selectAllButton.setActive( true );
		}

		this.$pagesCount.find( 'span' ).remove();
		this.$pagesCount.append(
			$( '<span>' )
				.html( mw.message( 'contenttransfer-pages-header-count', total, selected ).plain() )
		);
	};

	contentTransfer.widget.PageSelectorWidget.prototype.setNoPages = function () {
		this.$pageContainer.html(
			new OO.ui.LabelWidget( { label: mw.message( 'contenttransfer-no-pages-label' ).text() } ).$element
		);
	};

	contentTransfer.widget.PageSelectorWidget.prototype.displayError = function ( error ) {
		error = error || mw.message( 'contenttransfer-generic-error' ).text();
		this.$pageContainer.html(
			new OO.ui.MessageWidget( {
				type: 'error',
				label: error
			} ).$element
		);
	};

	contentTransfer.widget.PageSelectorWidget.prototype.displayPages = function ( response ) {
		if ( response.page_count === 0 ) {
			this.$pageContainer.append(
				new OO.ui.MessageWidget( {
					type: 'warning',
					label: mw.message( 'contenttransfer-no-pages-label' ).text()
				} ).$element
			);
			return;
		}
		this.$element.find( '.content-transfer-too-many-pages-warning' ).remove();

		for ( let idx = 0; idx < response.pages.length; idx++ ) {
			const page = response.pages[ idx ];
			const pageCheckbox = new OO.ui.CheckboxInputWidget( {
				name: page.id,
				selected: true,
				title: page.prefixed_text,
				data: page,
				classes: [ 'content-transfer-page-item-control' ]
			} );
			const pageLayout = new OO.ui.FieldLayout(
				pageCheckbox, {
					align: 'inline',
					label: page.prefixed_text
				} );
			pageLayout.$element.addClass( 'content-transfer-page-item' );
			pageCheckbox.on( 'change', this.onPageSelected.bind( this ), [ page.id ] );
			this.displayedPages[ page.id ] = { checkbox: pageCheckbox, selected: true };
			this.$pageContainer.append( pageLayout.$element );
		}

		if ( response.total > response.page_count ) {
			this.setTooManyPagesWarning( response.total, response.page_count );
		}

		this.updateSelectedCount();
		mw.hook( 'ext.contenttransfer.pageselector.pagesUpdated' ).fire( this, this.displayedPages, this.$pageContainer );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.onPageSelected = function ( pageId, value ) {
		if ( pageId in this.displayedPages ) {
			this.displayedPages[ pageId ].selected = value;
		}
		this.updateSelectedCount();
	};

	contentTransfer.widget.PageSelectorWidget.prototype.selectToggle = function () {
		if ( this.selectAllButton.isActive() ) {
			this.selectGroup( false );
			this.selectAllButton.setActive( false );
		} else {
			this.selectGroup( true );
			this.selectAllButton.setActive( true );
		}
	};

	contentTransfer.widget.PageSelectorWidget.prototype.selectGroup = function ( selected ) {
		for ( const id in this.displayedPages ) {
			if ( !this.displayedPages.hasOwnProperty( id ) ) {
				continue;
			}
			if ( !this.displayedPages[ id ].hasOwnProperty( 'checkbox' ) ) {
				continue;
			}
			this.displayedPages[ id ].checkbox.setSelected( selected );
		}
	};

	contentTransfer.widget.PageSelectorWidget.prototype.setPushWaitingNotice = function ( wait ) {
		this.pushPagesButton.setDisabled( wait );
		this.pushTargetPicker.setDisabled( wait );
		this.onlyModified.setDisabled( wait );
		this.selectAllButton.setDisabled( wait );
		for ( const name in this.filterInstances ) {
			if ( !this.filterInstances.hasOwnProperty( name ) ) {
				continue;
			}
			this.filterInstances[ name ].setDisabled( wait );
		}

		for ( const id in this.displayedPages ) {
			if ( !this.displayedPages.hasOwnProperty( id ) ) {
				continue;
			}
			if ( !this.displayedPages[ id ].hasOwnProperty( 'checkbox' ) ) {
				continue;
			}
			this.displayedPages[ id ].checkbox.setDisabled( wait );
		}

		if ( this.$element.find( '.loader' ).length > 0 ) {
			this.$element.find( '.loader' ).remove();
		}

		if ( wait ) {
			const loader = new OO.ui.ProgressBarWidget( { classes: [ 'loader' ] } );
			this.$element.prepend( loader.$element );
		}
	};

	contentTransfer.widget.PageSelectorWidget.prototype.stringToCallback = function ( cls ) {
		const parts = cls.split( '.' );
		let func = window[ parts[ 0 ] ];
		for ( let i = 1; i < parts.length; i++ ) {
			func = func[ parts[ i ] ];
		}

		return func;
	};

}( mediaWiki, jQuery ) );
