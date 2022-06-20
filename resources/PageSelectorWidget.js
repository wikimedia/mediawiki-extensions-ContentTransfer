( function( mw, $, d ) {
	window.contentTransfer = window.contentTransfer || {};
	window.contentTransfer.widget = window.contentTransfer.widget || {};

	contentTransfer.widget.PageSelectorWidget = function( cfg ) {
		this.$element = cfg.$element;
		this.filters = cfg.filters;

		this.api = new mw.Api( {
			ajax: {
				timeout: 300 * 1000 // 5 min
			}
		} );

		this.makeTargetPicker();
		this.makeFilters();
		this.makePageContainer();
		mw.hook( 'ext.contenttransfer.pageselector.init' ).fire( this );

		this.pushTargetPicker.getMenu().selectItem(
			this.pushTargetPicker.getMenu().findFirstSelectableItem()
		);

		this.loadPages();
	};

	OO.initClass( contentTransfer.widget.PageSelectorWidget );

	contentTransfer.widget.PageSelectorWidget.prototype.makeTargetPicker = function() {
		this.pushTargets = mw.config.get( 'ctPushTargets' );
		var pickerOptions = [];
		for( var key in this.pushTargets ) {
			if ( !this.pushTargets.hasOwnProperty( key ) ) {
				continue;
			}
			var pushTarget = this.pushTargets[ key ];
			pickerOptions.push( new OO.ui.MenuOptionWidget( {
				data: $.extend( {}, { key: key }, pushTarget ),
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

		this.pushPagesButton = new OO.ui.ButtonWidget( {
			label: mw.message( 'contenttransfer-push-pages-button-label' ).plain(),
			disabled: true,
			flags: [
				'primary',
				'progressive'
			]
		} );
		this.pushPagesButton.connect( this, { click: 'pushPages' } );

		this.includeRelated = new OO.ui.CheckboxInputWidget( { selected: true } );

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
		this.pushTargetPickerLayout = new OO.ui.HorizontalLayout( {
			items: [
				this.pickerLayout,
				new OO.ui.FieldLayout( this.includeRelated,{
					align: 'right',
					label: mw.message( 'contenttransfer-include-related' ).plain(),
					classes: [ 'related' ]
				} ),
				this.pushPagesButton
			]
		} );
		this.pushTargetPickerLayout.$element.addClass( 'content-transfer-targets-picker-layout' );
		this.$element.append( this.pushTargetPickerLayout.$element );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.makeFilters = function() {
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
			change: function() {
				this.modifiedSince.getValidity().done( function() {
					this.loadPages();
				}.bind( this ) );
			}
		} );

		var onlyModifiedLayout = new OO.ui.FieldLayout( this.onlyModified, {
			align: 'top',
			label: mw.message( 'contenttransfer-only-modified-label' ).text(),
			help: mw.message( 'contenttransfer-only-modified-help' ).text(),
			classes: [ 'only-modified-layout' ]
		} );
		var modifiedSinceLayout = new OO.ui.FieldLayout( this.modifiedSince, {
			align: 'top',
			label: mw.message( 'contenttransfer-modified-since-label' ).text()
		} );

		// Plugin filters
		this.filterInstances = {};
		var layouts = [];
		for ( var name in this.filters ) {
			if ( !this.filters.hasOwnProperty( name ) ) {
				continue;
			}

			var filter = this.filters[name],
				widgetClass = this.stringToCallback( filter.widgetClass ),
				widget = new widgetClass( $.extend( {}, true, {
					id: filter.id,
				}, filter.widgetData || {} ) ),
				layout = new OO.ui.FieldLayout( widget, {
					align: 'top',
					label: filter.displayName
				} );
			widget.connect( this, { change: 'loadPages' } );
			layouts.push( layout );
			this.filterInstances[name] = widget;
		}

		var $filterLayout = $( '<div>' ).addClass( 'contenttransfer-filter-layout' );
		$filterLayout.append(
			new OO.ui.HorizontalLayout( { classes: [ 'bottom-margin table-50' ], items: [
					modifiedSinceLayout,
					onlyModifiedLayout
				] } ).$element,
			new OO.ui.HorizontalLayout( { items: layouts } ).$element
		);

		this.$element.append( $filterLayout );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.makePageContainer = function() {
		this.$pageContainer = $( '<div>' ).addClass( 'content-transfer-page-container' );
		this.$element.append( this.$pageContainer );

		this.$pagesHeader = $( '<div>' ).addClass( 'content-transfer-pages-header' );
		this.$pagesHeader.insertBefore( this.$pageContainer );
		var headerLayout = new OO.ui.HorizontalLayout( {
			classes: [ 'header-layout' ]
		} );
		this.selectAllButton = new OO.ui.ButtonWidget( {
			label: 'Select all',
			framed: false
		} );
		this.selectNoneButton = new OO.ui.ButtonWidget( {
			label: 'Select none',
			framed: false
		} );
		this.selectAllButton.connect( this, { click: 'selectAll' } );
		this.selectNoneButton.connect( this, { click: 'selectNone' } );

		headerLayout.$element.append(
			$( '<h2>' ).html( mw.message( 'contenttransfer-pages-header' ).plain() ),
			this.selectAllButton.$element,
			this.selectNoneButton.$element
		);


		this.$pagesHeader.append( headerLayout.$element );
		this.updateSelectedCount();
	};

	contentTransfer.widget.PageSelectorWidget.prototype.pushPages = function() {
		if( !this.currentPushTarget ) {
			return;
		}

		var selectedPageIds = [];
		for( var pageId in this.displayedPages ) {
			if ( this.displayedPages[ pageId ].selected ) {
				selectedPageIds.push( pageId );
			}
		}

		this.setPushWaitingNotice( true );
		this.api.get( {
			action: "content-transfer-push-info",
			titles: JSON.stringify( selectedPageIds ),
			onlyModified: this.onlyModified.isSelected() ? 1 : 0,
			modifiedSince: this.modifiedSince.getValue(),
			includeRelated: this.includeRelated.isSelected() ? 1 : 0,
			target: this.currentPushTarget
		} )
			.done( function( response ) {
				var windowManager = OO.ui.getWindowManager();
				var cfg = {
					grouped: response.grouped,
					joined: response.joined,
					pushTarget: $.extend( {
						id: this.currentPushTarget,
						selectedUser: this.currentPushUser
					}, this.pushTargets[this.currentPushTarget] ),
					originalIds: selectedPageIds
				};

				var dialog = new contentTransfer.dialog.Push( cfg );
				windowManager.addWindows( [ dialog ] );
				windowManager.openWindow( dialog );
				this.setPushWaitingNotice( false );
			}.bind( this ) );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.onPushTargetChanged = function( selected ) {
		if ( !selected ) {
			this.currentPushTarget = null;
			this.currentPushUser = null;
			return;
		}
		var data = selected.getData(),
			users = data.users;

		this.currentPushTarget = data.key;
		this.loadPages();
		if ( users.length === 0 ) {
			return;
		}
		this.currentPushUser = users[0];
		if ( users.length === 1 ) {
			this.userSelectorLayout.$element.hide();
			return;
		}
		var options = [];
		for( var i = 0; i < users.length; i++ ) {
			options.push( {
				data: users[i]
			} );
		}
		this.userSelector.setOptions( options );
		this.userSelectorLayout.$element.show();
		this.userSelector.setValue( this.currentPushUser );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.onPushUserChanged = function( value ) {
		this.currentPushUser = value;
	};

	contentTransfer.widget.PageSelectorWidget.prototype.getComboValue = function( value, type, key ) {
		if ( !value ) {
			return false;
		}
		key = key || 'id';
		for( var i = 0; i < this.filterData[type].length; i++ ) {
			if ( this.filterData[type][i].text === value ) {
				value = this.filterData[type][i][key];
				if ( key === 'id' ) {
					return parseInt( value );
				}
				return value;
			}
		}

		return null;
	};

	contentTransfer.widget.PageSelectorWidget.prototype.getFilterData = function() {
		var values = {};
		// Plugin filters

		for( var name in this.filterInstances ) {
			if ( !this.filterInstances.hasOwnProperty( name ) ) {
				continue;
			}
			var filterInstance = this.filterInstances[name];
			values[filterInstance.$element.attr( 'id' )] = filterInstance.getValue();
		}
		// Build-in
		values.modifiedSince = this.modifiedSince.getValue();
		values.onlyModified = this.onlyModified.isSelected();

		return $.extend( values, {
			target: this.currentPushTarget
		} );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.loadPages = function() {
		if ( !this.currentPushTarget ) {
			return;
		}
		var filterData = this.getFilterData();
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
			.done( function( response ) {
				this.setPagesLoading( false );
				this.displayPages( response );
			}.bind( this ) );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.clearPages = function() {
		this.$pageContainer.children().remove();
		this.displayedPages = {};
		this.updateSelectedCount();
	};

	contentTransfer.widget.PageSelectorWidget.prototype.setPagesLoading = function( loading ) {
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

	contentTransfer.widget.PageSelectorWidget.prototype.setTooManyPagesWarning = function( total, retrieved ) {
		var icon = new OO.ui.IconWidget( {
			icon: 'alert'
		} );
		var label = new OO.ui.LabelWidget( {
			label: mw.message( 'contenttransfer-too-many-pages-warning', total, retrieved ).text()
		} );
		var layout = new OO.ui.HorizontalLayout( {
			items: [
				icon,
				label
			]
		} );
		layout.$element.addClass( 'content-transfer-too-many-pages-warning' );

		this.$pageContainer.prepend( layout.$element );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.updateSelectedCount = function() {
		var total = 0,
			selected = 0;

		for( var pageId in this.displayedPages ) {
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

		this.$pagesHeader.find( 'span.selected-count' ).remove();
		this.$pagesHeader.append(
			$( '<span>' )
				.addClass( 'selected-count' )
				.html( mw.message( 'contenttransfer-pages-header-count', total, selected ).plain() )
		);
	};

	contentTransfer.widget.PageSelectorWidget.prototype.setNoPages = function() {
		this.$pageContainer.html(
			new OO.ui.LabelWidget( { label: mw.message( 'contenttransfer-no-pages-label' ).text() } ).$element
		);
	};

	contentTransfer.widget.PageSelectorWidget.prototype.displayError = function( error ) {
		error = error || mw.message( 'contenttransfer-generic-error' ).text();
		this.$pageContainer.html(
			new OO.ui.HorizontalLayout( {
				items: [
					new OO.ui.IconWidget( {
						icon: 'block',
						flags: [ 'destructive' ]
					} ),
					new OO.ui.LabelWidget( { label: error } )
				],
				classes: [ 'contenttransfer-error' ]
			} ).$element
		);
	};

	contentTransfer.widget.PageSelectorWidget.prototype.displayPages = function ( response ) {
		if( response.page_count === 0 ) {
			this.$pageContainer.append(
				new OO.ui.HorizontalLayout( {
					items: [
						new OO.ui.IconWidget( { icon: 'alert' } ),
						new OO.ui.LabelWidget( {
							label: mw.message( 'contenttransfer-no-pages-label' ).text()
						} )
					]
				} ).$element
			);
			return;
		}
		this.$element.find( '.content-transfer-too-many-pages-warning' ).remove();


		for( var idx = 0; idx < response.pages.length; idx++ ) {
			var page = response.pages[idx];
			var pageCheckbox = new OO.ui.CheckboxInputWidget( {
				name: page.id,
				selected: true,
				title: page.prefixed_text,
				data:  page,
				classes: [ 'content-transfer-page-item-control' ]
			} );
			var pageLayout = new OO.ui.FieldLayout(
				pageCheckbox, {
					align: 'inline',
					label: page.prefixed_text
				} );
			pageLayout.$element.addClass( 'content-transfer-page-item' );
			pageCheckbox.on( 'change', this.onPageSelected.bind( this ), [ page.id ] );
			this.displayedPages[page.id] = { checkbox: pageCheckbox, selected: true };
			this.$pageContainer.append( pageLayout.$element );
		}

		if( response.total > response.page_count ) {
			this.setTooManyPagesWarning( response.total, response.page_count);
		}

		this.updateSelectedCount();
		mw.hook( 'ext.contenttransfer.pageselector.pagesUpdated' ).fire( this, this.displayedPages, this.$pageContainer );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.onPageSelected = function ( pageId, value ) {
		if( pageId in this.displayedPages ) {
			this.displayedPages[pageId].selected = value;
		}
		this.updateSelectedCount();
	};

	contentTransfer.widget.PageSelectorWidget.prototype.selectAll = function () {
		this.selectGroup( true );
	};
	contentTransfer.widget.PageSelectorWidget.prototype.selectNone = function () {
		this.selectGroup( false );
	};

	contentTransfer.widget.PageSelectorWidget.prototype.selectGroup = function ( selected ) {
		for( var id in this.displayedPages ) {
			if ( !this.displayedPages.hasOwnProperty( id ) ) {
				continue;
			}
			if ( !this.displayedPages[id].hasOwnProperty( 'checkbox' ) ) {
				continue;
			}
			this.displayedPages[id].checkbox.setSelected( selected );
		}
	};

	contentTransfer.widget.PageSelectorWidget.prototype.setPushWaitingNotice = function ( wait ) {
		this.pushPagesButton.setDisabled( wait );
		this.pushTargetPicker.setDisabled( wait );
		this.onlyModified.setDisabled( wait );
		this.selectAllButton.setDisabled( wait );
		this.selectNoneButton.setDisabled( wait );
		for ( var name in this.filterInstances ) {
			if ( !this.filterInstances.hasOwnProperty( name ) ) {
				continue;
			}
			this.filterInstances[name].setDisabled( wait );
		}

		for( var id in this.displayedPages ) {
			if ( !this.displayedPages.hasOwnProperty( id ) ) {
				continue;
			}
			if ( !this.displayedPages[id].hasOwnProperty( 'checkbox' ) ) {
				continue;
			}
			this.displayedPages[id].checkbox.setDisabled( wait );
		}

		if ( this.$element.find( '.loader' ).length > 0 ) {
			this.$element.find( '.loader' ).remove();
		}

		if ( wait ) {
			var loader = new OO.ui.ProgressBarWidget( { classes: [ 'loader'] } );
			this.$element.prepend( loader.$element );
		}
	};

	contentTransfer.widget.PageSelectorWidget.prototype.stringToCallback = function ( cls ) {
		var parts = cls.split( '.' );
		var func = window[parts[0]];
		for( var i = 1; i < parts.length; i++ ) {
			func = func[parts[i]];
		}

		return func;
	};

} )( mediaWiki, jQuery, document );
