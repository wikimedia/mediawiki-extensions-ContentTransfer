( function( mw, $, d ) {
	contentTransfer.dialog = {};
	contentTransfer.dialog.Push = function( cfg ) {
		cfg = cfg || {};

		this.groupedInfo = cfg.grouped;
		this.joinedInfo = cfg.joined;
		this.originalySelected = cfg.originalIds;
		this.pushTarget = cfg.pushTarget;
		this.groupsToTransfer = {};
		this.skipPages = [];
		this.enableBeta = mw.config.get( 'ctEnableBeta' );
		this.original = this.groupedInfo.original || [];
		delete( this.groupedInfo.original );
		this.checkboxes = {};

		this.progressStep = 1;
		this.pushedPages = [];

		this.api = new mw.Api();

		cfg.size = 'large';

		contentTransfer.dialog.Push.parent.call( this, cfg );
	};

	OO.inheritClass( contentTransfer.dialog.Push, OO.ui.ProcessDialog );

	contentTransfer.dialog.Push.static.name = 'ctPushDialog';

	contentTransfer.dialog.Push.static.title = mw.message( 'contenttransfer-dialog-push-title' ).plain();

	contentTransfer.dialog.Push.static.actions = [
		{
			action: 'cancel',
			label: mw.message( 'contenttransfer-dialog-push-action-cancel-label' ).plain(),
			flags: 'safe',
			modes: [ 'preview', 'progress', 'report' ]
		},
		{
			action: 'doPush',
			label: mw.message( 'contenttransfer-dialog-push-action-do-push-label' ).plain(),
			flags: [ 'primary', 'progressive' ],
			modes: [ 'preview' ]
		},
		{
			action: 'done',
			label: mw.message( 'contenttransfer-dialog-push-action-done-label' ).plain(),
			flags: [ 'primary', 'progressive' ],
			modes: [ 'progress', 'report' ]
		}
	];

	contentTransfer.dialog.Push.prototype.getSetupProcess = function ( data ) {
		/* eslint-disable-next-line */
		return contentTransfer.dialog.Push.parent.prototype.getSetupProcess.call( this, data )
			.next( function () {
				this.actions.setMode( 'preview' );
			}, this );
	};

	contentTransfer.dialog.Push.prototype.initialize = function() {
		contentTransfer.dialog.Push.parent.prototype.initialize.call( this );

		this.layout = new contentTransfer.layout.Push();
		this.layout.addStage( {
			name: 'preview',
			label: mw.message( 'contenttransfer-dialog-push-preview-layout-label' ).plain(),
			content: this.makePreviewPanel()
		} );

		this.layout.addStage( {
			name: 'progress',
			label: mw.message( 'contenttransfer-dialog-push-progress-layout-label' ).plain(),
			content: this.makeProgressPanel()
		} );

		this.layout.addStage( {
			name: 'report',
			label: mw.message( 'contenttransfer-dialog-push-report-layout-label' ).plain(),
			content: this.makeReportPanel()
		} );

		this.layout.firstStage();

		this.$body.append( this.layout.$element );
	};

	contentTransfer.dialog.Push.prototype.getBodyHeight = function () {
		// Fixed height, prevents a lot of headache
		return 500;
	};

	contentTransfer.dialog.Push.prototype.makePreviewPanel = function() {
		this.$previewPanel = $( '<div>' ).addClass( 'content-transfer-dialog-push-preview-panel' );

		let $targetCnt = $( '<div>' ).addClass( 'content-transfer-preview-target-info' );
		var pushTargetLabel = this.pushTarget.displayText || this.pushTarget.id;
		var targetInfo = new OO.ui.LabelWidget( {
			label: mw.message( 'contenttransfer-dialog-preview-target-info', pushTargetLabel ).text(),
		} );
		$targetCnt.append( targetInfo.$element );

		if ( this.pushTarget.pushToDraft ) {
			$targetCnt.append(
				new OO.ui.MessageWidget( {
					type: 'warning',
					inline: true,
					label: mw.message( 'contenttransfer-dialog-preview-target-draft' ).text()
				} ).$element
			);
		}
		this.$previewPanel.append( $targetCnt );

		this.makeGroup( 'original', this.original, true, false );
		if ( !$.isEmptyObject( this.groupedInfo ) ) {
			this.$previewPanel.append( new OO.ui.LabelWidget( {
				label: mw.message( 'content-transfer-push-related-pages' ).text(),
				classes: [ 'content-transfer-push-related-pages-label' ]
			} ).$element );
		}

		for( var group in this.groupedInfo ) {
			if ( !this.groupedInfo.hasOwnProperty( group ) ) {
				continue;
			}
			var pages = this.groupedInfo[group];
			this.groupsToTransfer[group] = true;
			this.makeGroup( group, pages, false, true );
		}
		return this.$previewPanel;
	};

	contentTransfer.dialog.Push.prototype.makeGroup = function( name, pages, expanded, selectable ) {
		var groupLayout, i;
		// contenttransfer-dialog-push-preview-page-group-label-original
		// contenttransfer-dialog-push-preview-page-group-label-wikipage
		// contenttransfer-dialog-push-preview-page-group-label-file
		// contenttransfer-dialog-push-preview-page-group-label-category
		// contenttransfer-dialog-push-preview-page-group-label-template
		var totalPages = $.type( pages ) === 'array' ? pages.length :
			this.getSubgroupedPageCount( pages );
		var groupTitle = mw.message(
			'contenttransfer-dialog-push-preview-page-group-label-' + name,
			totalPages
		).text();

		this.checkboxes[name] = [];
		if ( selectable ) {
			var checkbox = new OO.ui.CheckboxInputWidget( {
				selected: true
			} );
			checkbox.on( 'change', this.onGroupCheck.bind( this ), [ name ] );
		}
		var button = new OO.ui.ButtonInputWidget( {
			framed: false,
			label: groupTitle,
			icon: contentTransfer.dialog.Push.static.groupIcons[name],
			indicator: 'down'
		} );

		var groupLayoutConfig = {
			align: 'inline',
			classes: [ 'content-transfer-dialog-push-preview-panel-group-button' ]
		};
		if ( selectable ) {
			groupLayout = new OO.ui.ActionFieldLayout( checkbox, button, groupLayoutConfig );
		} else {
			groupLayout = new OO.ui.FieldLayout( button, groupLayoutConfig );
		}


		var $pageGroup = $( '<div>' ).addClass( 'content-transfer-dialog-push-preview-panel-page-group' );
		if( expanded ) {
			$pageGroup.addClass( 'page-group-visible' );
			button.setIndicator( 'up' );
		}
		button.on( 'click', this.expandPageGroup, [ button, $pageGroup ] );

		this.$previewPanel.append( groupLayout.$element );

		if ( $.type( pages ) === 'object' ) {
			for( var subgroup in pages ) {
				if ( !pages.hasOwnProperty( subgroup ) ) {
					continue;
				}
				if ( pages[subgroup].length === 0 ) {
					continue;
				}
				this.checkboxes[name + '#' + subgroup] = [];
				var subgroupCheckbox = new OO.ui.CheckboxInputWidget( {
					selected: true,
					classes: [ 'content-transfer-dialog-push-check-subgroup' ]
				} );
				this.checkboxes[name].push( subgroupCheckbox );

				var $subpageGroup = $( '<div>' )
					.addClass( 'content-transfer-dialog-push-preview-panel-page-group subpage-group page-group-visible' );
				subgroupCheckbox.on( 'change', this.onSubgroupCheckbox.bind( this ), [ name, subgroup ] );
				$pageGroup.append( new OO.ui.FieldLayout( subgroupCheckbox, {
					align: 'inline',
					label: mw.message( 'contenttransfer-dialog-push-subgroup-label-' + subgroup ).text()
				} ).$element );

				for ( i = 0; i < pages[subgroup].length; i++ ) {
					this.addPageToGroup( pages[subgroup][i], $subpageGroup, selectable, name, subgroup );
				}
				$pageGroup.append( $subpageGroup );
			}
		} else {
			for( i = 0; i < pages.length; i++ ) {
				this.addPageToGroup( pages[i], $pageGroup, selectable, name );
			}
		}

		this.$previewPanel.append( $pageGroup );
	};

	contentTransfer.dialog.Push.prototype.addPageToGroup = function( page, $element, selectable, group, subgroup ) {
		if ( selectable ) {
			var singlePageCheck = new OO.ui.CheckboxInputWidget( {
				data: {
					pid: page.id
				},
				selected: true
			} );
			if ( subgroup ) {
				var subgroupKey = group + '#' + subgroup;
				this.checkboxes[subgroupKey].push( singlePageCheck );
			}

			this.checkboxes[group].push( singlePageCheck );
			singlePageCheck.on( 'change', this.onSinglePageCheck.bind( this ), [ page ] );
		}
		$element.append(
			$( '<span>' )
			.addClass( 'content-transfer-dialog-push-preview-panel-page-item' )
			.append(
				( selectable ? singlePageCheck.$element : '' ),
				$( '<a>' )
				.attr( 'href', page.uri )
				.attr( 'target', '_blank' ).html( page.title )
			)
		);
	};

	contentTransfer.dialog.Push.prototype.getSubgroupedPageCount = function( pages ) {
		var count = 0;
		for( var subgroup in pages ) {
			if ( !pages.hasOwnProperty( subgroup ) ) {
				continue;
			}
			count += pages[subgroup].length;
		}

		return count;
	};

	contentTransfer.dialog.Push.prototype.makeProgressPanel = function() {
		this.$progressPanel = $( '<div>' ).addClass( 'content-transfer-progress-panel' );
		this.$pushTarget = $( '<div>' )
			.addClass( 'content-transfer-progress-panel-push-target' )
			.html(
				mw.message(
				'contenttransfer-progress-push-target-label', this.pushTarget.displayText || this.pushTarget.id
				).text()
			);
		this.$currentOperation = $( '<span>' ).addClass( 'content-transfer-progress-panel-current-op' );
		this.progressBar = new OO.ui.ProgressBarWidget( {
			progress: 0
		} );
		this.$logContainer = $( '<div>' ).addClass( 'content-transfer-progress-panel-log-container' );

		this.$progressPanel.append(
			this.$pushTarget,
			this.$currentOperation,
			this.progressBar.$element,
			this.$logContainer
		);
		return this.$progressPanel;
	};

	contentTransfer.dialog.Push.prototype.makeReportPanel = function() {
		this.$reportPanel = $( '<div>' ).addClass( 'content-transfer-report-panel' );

		this.reportIcon = new OO.ui.IconWidget( { icon: 'check' } );
		this.$reportCount = $( '<span>' ).addClass( 'content-transfer-report-panel-count' );
		this.$reportFailures = $( '<div>' ).addClass( 'content-transfer-report-panel-failures hidden' );
		this.$reportFailures.append(
			$( '<div>' ).addClass( 'content-transfer-report-panel-failed' )
			.text( mw.message( 'contenttransfer-report-failure-label' ).text() )
		);

		this.$reportPanel.append(
			this.reportIcon.$element,
			this.$reportCount,
			this.$reportFailures
		);

		return this.$reportPanel;
	};

	contentTransfer.dialog.Push.prototype.expandPageGroup = function( button, $pageGroup ) {
		if( $pageGroup.hasClass( 'page-group-visible' ) ) {
			$pageGroup.removeClass( 'page-group-visible' );
			button.setIndicator( 'down' );
			return;
		}
		$pageGroup.addClass( 'page-group-visible' );
		button.setIndicator( 'up' );
	};

	contentTransfer.dialog.Push.prototype.getActionProcess = function( action ) {
		var me = this;

		if ( action === 'doPush' ) {
			this.actions.setAbilities( { cancel: true, done: true, doPush: false } );
			this.actions.setMode( 'progress' );
			return new OO.ui.Process( function() {
				me.layout.nextStage();
				return me.pushPages();
			} );
		}
		if( action === 'cancel' || action === 'done' ) {
			var dialog = this;
			return new OO.ui.Process( function () {
				dialog.close();
			} );
		}

		return contentTransfer.dialog.Push.super.prototype.getActionProcess.call( this, action );
	};

	contentTransfer.dialog.Push.prototype.pushPages = function() {
		var dfd = $.Deferred();

		this.actions.setAbilities( {
			cancel: false,
			done: false,
			doPush: false
		} );
		this.filterOutExcluded();
		this.progressStep = 100 / Object.keys( this.joinedInfo ).length;
		this.doPush( dfd, true );
		return dfd.promise();
	};

	contentTransfer.dialog.Push.prototype.doPush = function( dfd, noProgress, page ) {
		var me = this;
		var force = false;

		if( !page ) {
			for( var dbKey in this.joinedInfo ) {
				if ( !this.joinedInfo.hasOwnProperty( dbKey ) ) {
					continue;
				}
				page = this.joinedInfo[ dbKey ];
				delete( this.joinedInfo[ dbKey ] );
				break;
			}
		} else {
			force = true;
		}

		if( !noProgress ) {
			this.progressBar.setProgress( this.progressBar.getProgress() + this.progressStep );
		}

		if( !page ) {
			me.pushDone();
			return dfd.resolve();
		}

		this.$currentOperation.html( mw.message( 'contenttransfer-progress-current-operation', page.title ).text() );

		var data = {
			articleId: page.id,
			pushTarget: JSON.stringify( this.pushTarget )
		};
		if( force ) {
			data.force = 1;
		}


		this.api.postWithEditToken( $.extend( {
			action: 'content-transfer-do-push-single'
		}, data ) )
			.done( function( response ) {
				if( response.success === 1 ) {
					// Page pushed successfully, go on
					me.addProgressItem( page, true );
					me.doPush( dfd );
				} else {
					var $item = me.addProgressItem( page, false, response.message );
					if( response.userAction ) {
						// Pushing failed, user is required to take action
						me.askUser( response, $item ).done( function( userResponse ) {
							if( userResponse == 'force' ) {
								// User wants to force retry
								me.removeProgressItem( page, $item );
								me.doPush( dfd, true, page );
							} else if( userResponse == 'skip' ) {
								// Skip this page
								me.doPush( dfd );
							} else if( userResponse == 'stop' ) {
								// Stop further pushing
								me.pushDone( true );
								dfd.resolve();
							}
						} );
					} else {
						// Pushing failed, but nothing user can do about it
						me.doPush( dfd );
					}
				}
			} )
			.fail( function( response ) {
				me.addProgressItem( page, false, 'Api error' );
				me.doPush( dfd );
			} );
	};

	contentTransfer.dialog.Push.prototype.askUser = function( response, $pageItem ) {
		var dfd = $.Deferred();
		var $actionCnt = $( '<div> ').addClass( 'content-transfer-progress-user-action-container' );
		var label = new OO.ui.LabelWidget( { label: response.message } );
		var btnSkip = new OO.ui.ButtonWidget( {
			label: mw.message( 'contenttransfer-progress-user-action-skip' ).text(),
			framed: false,
			flags: [
				'primary'
			]
		} );
		var btnStop = new OO.ui.ButtonWidget( {
			label: mw.message( 'contenttransfer-progress-user-action-stop' ).text(),
			framed: false,
			icon: 'cancel',
			flags: [
				'destructive'
			]
		} );
		var btnForce = new OO.ui.ButtonWidget( {
			label: mw.message( 'contenttransfer-progress-user-action-force' ).text(),
			framed: false,
			flags: [
				'primary',
			]
		} );

		$actionCnt.append( label.$element );

		if( response.userAction === 'force' ) {
			$actionCnt.append( btnForce.$element );
		}

		$actionCnt.append( btnSkip.$element );
		$actionCnt.append( btnStop.$element );

		btnSkip.on( 'click', function( e ) {
			$actionCnt.remove();
			dfd.resolve( 'skip' );
		} );
		btnStop.on( 'click', function( e ) {
			$actionCnt.remove();
			dfd.resolve( 'stop' );
		} );
		btnForce.on( 'click', function( e ) {
			$actionCnt.remove();
			dfd.resolve( 'force' );
		} );

		$pageItem.append( $actionCnt );
		return dfd.promise();
	};

	contentTransfer.dialog.Push.prototype.addProgressItem = function( page, success, message ) {
		this.pushedPages.push( {
			id: page.id,
			title: page.title,
			success: success,
			message: message || ''
		} );
		var $item = $( '<div>' )
				.addClass( 'content-transfer-progress-item' )
				.append( $( '<span>' ).addClass( 'content-transfer-progress-item-label' ).html( page.title ) );

		if( success ) {
			$item.prepend( new OO.ui.IconWidget( { icon: 'check' } ).$element );
		} else {
			$item.prepend( new OO.ui.IconWidget( { icon: 'close' } ).$element );
		}
		this.$logContainer.append( $item );

		// Always scroll to bottom of the log
		this.$logContainer.animate( { scrollTop: this.$logContainer.height() }, 1000 );
		return $item;
	};

	contentTransfer.dialog.Push.prototype.removeProgressItem = function( page, $pageItem ) {
		$pageItem.remove();
		for( var idx in this.pushedPages ) {
			if( this.pushedPages[ idx ].id === page.id ) {
				this.pushedPages.splice( idx, 1 );
				return;
			}
		}
	};

	contentTransfer.dialog.Push.prototype.pushDone = function( interrupted ) {
		var successCount = 0;
		var total = 0;
		var pagesToPurge = [];
		for( var idx in this.pushedPages ) {
			total++;

			var pushedPage = this.pushedPages[ idx ];
			if( pushedPage.success === false ) {
				if ( this.$reportFailures.hasClass( 'hidden' ) ) {
					this.$reportFailures.removeClass( 'hidden' );
				}
				var $failure = $( '<p>' ).append(
					$( '<span>' ).html( pushedPage.title ),
					$( '<span>' ).addClass( 'content-transfer-report-failure-reason' ).html( pushedPage.message )
				);
				this.$reportFailures.append( $failure );
			} else {
				successCount++;
				pagesToPurge.push( pushedPage.title );
			}
		}
		if( interrupted ) {
			this.reportIcon.setIcon( 'close' );
			this.$reportCount.html( mw.message( 'contenttransfer-report-interrupted', successCount ).text() );
		} else {
			this.$reportCount.html( mw.message( 'contenttransfer-report-success-count', successCount, total ).text() );
		}
		if( successCount < total ) {
			this.reportIcon.setIcon( 'close' );
		}

		if( successCount > 0 ) {
			this.purgePages( pagesToPurge ).done( function() {
				this.showReport();
			}.bind( this ) );
		} else {
			this.showReport();
		}
	};

	contentTransfer.dialog.Push.prototype.purgePages = function( pageTitles ) {
		var dfd = $.Deferred();
		this.$currentOperation.html( mw.message( 'contenttransfer-progress-purge-pages' ).plain() );

		this.api.postWithEditToken(
			{
				titles: pageTitles,
				pushTarget: this.pushTarget.url
			},
			'content-transfer-purge-pages'
		).done( function() {
			dfd.resolve();
		} )
		.fail( function() {
			dfd.resolve();
		} );
		return dfd.promise();
	};

	contentTransfer.dialog.Push.prototype.showReport = function() {
		this.layout.nextStage();
		this.actions.setAbilities( { done: true } );
	};

	contentTransfer.dialog.Push.prototype.onGroupCheck = function( group, value ) {
		for( var i = 0; i < this.checkboxes[group].length; i++ ) {
			this.checkboxes[group][i].setSelected( value );
		}
	};

	contentTransfer.dialog.Push.prototype.filterOutExcluded = function() {
		for( var dbKey in this.joinedInfo ) {
			if ( !this.joinedInfo.hasOwnProperty( dbKey ) ) {
				continue;
			}
			var page = this.joinedInfo[ dbKey ];
			if ( this.skipPages.indexOf( page.id ) !== -1 ) {
				delete( this.joinedInfo[dbKey] );
			}
		}
	};

	contentTransfer.dialog.Push.prototype.onSinglePageCheck = function( page, value ) {
		if ( !page ) {
			return;
		}
		if ( value && this.skipPages.indexOf( page.id ) !== -1 ) {
			this.skipPages.splice( this.skipPages.indexOf( page.id ), 1 );
		} else if ( !value ) {
			this.skipPages.push( page.id );
		}
	};

	contentTransfer.dialog.Push.prototype.onSubgroupCheckbox = function( group, subgroup, value ) {
		var key = group + '#' + subgroup;
		for( var i = 0; i < this.checkboxes[key].length; i++ ) {
			this.checkboxes[key][i].setSelected( value );
		}
	};

	contentTransfer.dialog.Push.static.groupIcons = {
		wikipage: 'articles',
		category: 'tag',
		template: 'code',
		file: 'image'
	};
} )( mediaWiki, jQuery, document );
