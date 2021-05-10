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
			action: 'doPush',
			label: mw.message( 'contenttransfer-dialog-push-action-do-push-label' ).plain(),
			flags: [ 'primary' ],
			disabled: false
		},
		{
			action: 'done',
			label: mw.message( 'contenttransfer-dialog-push-action-done-label' ).plain(),
			flags: [ 'primary' ],
			disabled: true
		},
		{
			action: 'cancel',
			label: mw.message( 'contenttransfer-dialog-push-action-cancel-label' ).plain(),
			flags: 'safe'
		}
	];

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
		var targetInfo = new OO.ui.LabelWidget( {
			label: mw.message( 'contenttransfer-dialog-preview-target-info', this.pushTarget.url ).text()
		} );
		targetInfo.$element.addClass( 'content-transfer-preview-target-info' );
		if( this.pushTarget.pushToDraft ) {
			targetInfo.setLabel(
				new OO.ui.HtmlSnippet(
					'<span>' + targetInfo.getLabel() + '</span>' +
					'<span class="push-to-draft">' +
						mw.message( 'contenttransfer-dialog-preview-target-draft' ).text() +
					'</span>'
				)
			);
			targetInfo.$element.addClass( 'push-to-draft' );
		}

		this.$previewPanel.append( targetInfo.$element );

		for( var group in this.groupedInfo ) {
			var pages = this.groupedInfo[group];
			this.groupsToTransfer[group] = true;
			// contenttransfer-dialog-push-preview-page-group-label-wikipage
			// contenttransfer-dialog-push-preview-page-group-label-file
			// contenttransfer-dialog-push-preview-page-group-label-category
			// contenttransfer-dialog-push-preview-page-group-label-template
			var groupTitle = mw.message(
				'contenttransfer-dialog-push-preview-page-group-label-' + group,
				pages.length
			).text();

			var checkbox = new OO.ui.CheckboxInputWidget( {
				selected: true
			} );
			checkbox.on( 'change', this.onGroupCheck.bind( this ), [ group ] );
			var button = new OO.ui.ButtonInputWidget( {
				framed: false,
				label: groupTitle,
				icon: contentTransfer.dialog.Push.static.groupIcons[group],
				indicator: 'down'
			} );

			var groupLayout = new OO.ui.ActionFieldLayout( checkbox, button, {
				align: 'inline',
				classes: [ 'content-transfer-dialog-push-preview-panel-group-button' ]
			} );

			var $pageGroup = $( '<div>' ).addClass( 'content-transfer-dialog-push-preview-panel-page-group' );
			if( group === 'wikipage' ) {
				$pageGroup.addClass( 'page-group-visible' );
				button.setIndicator( 'up' );
			}
			button.on( 'click', this.expandPageGroup, [ button, $pageGroup ] );

			this.$previewPanel.append( groupLayout.$element );

			for( var idx in pages ) {
				if ( this.enableBeta ) {
					var singlePageCheck = new OO.ui.CheckboxInputWidget( {
						data: {
							pid: pages[idx].id
						},
						selected: true
					} );
					singlePageCheck.on( 'change', this.onSinglePageCheck.bind( this ), [ pages[idx], group ] );
				}
				$pageGroup.append(
					$( '<span>' )
						.addClass( 'content-transfer-dialog-push-preview-panel-page-item' )
						.append(
							( this.enableBeta ? singlePageCheck.$element : '' ),
							$( '<a>' )
								.attr( 'href', pages[ idx ].uri )
								.attr( 'target', '_blank' ).html( pages[ idx ].title )
						)
				);
			}

			this.$previewPanel.append( $pageGroup );
		}
		return this.$previewPanel;
	}

	contentTransfer.dialog.Push.prototype.makeProgressPanel = function() {
		this.$progressPanel = $( '<div>' ).addClass( 'content-transfer-progress-panel' );
		this.$pushTarget = $( '<div>' )
			.addClass( 'content-transfer-progress-panel-push-target' )
			.html( mw.message( 'contenttransfer-progress-push-target-label', this.pushTarget.url ).text() );
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
	}

	contentTransfer.dialog.Push.prototype.makeReportPanel = function() {
		this.$reportPanel = $( '<div>' ).addClass( 'content-transfer-report-panel' );

		this.reportIcon = new OO.ui.IconWidget( { icon: 'check' } );
		this.$reportCount = $( '<span>' ).addClass( 'content-transfer-report-panel-count' );
		this.$reportFailures = $( '<div>' ).addClass( 'content-transfer-report-panel-failures' );

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

		if( action === 'doPush' ){
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
			label: "Skip",
			framed: false,
			flags: [
				'primary'
			]
		} );
		var btnStop = new OO.ui.ButtonWidget( {
			label: "Stop pushing",
			framed: false,
			icon: 'cancel',
			flags: [
				'destructive'
			]
		} );
		var btnForce = new OO.ui.ButtonWidget( {
			label: "Force retry",
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
				this.$reportFailures.append(
					$( '<span>' ).html( mw.message( 'contenttransfer-report-failure', pushedPage.title ).text() ),
					$( '<span>' ).addClass( 'content-transfer-report-failure-reason' ).html( pushedPage.message )
				);
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

	contentTransfer.dialog.Push.prototype.onGroupCheck = function( group ) {
		this.groupsToTransfer[group] = !this.groupsToTransfer[group];
	};

	contentTransfer.dialog.Push.prototype.filterOutExcluded = function() {
		for( var dbKey in this.joinedInfo ) {
			if ( !this.joinedInfo.hasOwnProperty( dbKey ) ) {
				continue;
			}
			var page = this.joinedInfo[ dbKey ];
			if ( !this.groupsToTransfer.hasOwnProperty( page.type ) || this.groupsToTransfer[page.type] === false ) {
				delete( this.joinedInfo[dbKey] );
			}
			if ( this.skipPages.indexOf( page.id ) !== -1 ) {
				delete( this.joinedInfo[dbKey] );
			}
		}
	};

	contentTransfer.dialog.Push.prototype.onSinglePageCheck = function( page, group ) {
		if ( this.skipPages.indexOf( page.id ) !== -1 ) {
			this.skipPages.splice( this.skipPages.indexOf( page.id ), 1 );
		} else {
			this.skipPages.push( page.id );
		}
	};

	contentTransfer.dialog.Push.static.groupIcons = {
		wikipage: 'articles',
		category: 'tag',
		template: 'code',
		file: 'imageGallery'
	};
} )( mediaWiki, jQuery, document );
