window.contentTransfer = window.contentTransfer || {};
window.contentTransfer.widget = window.contentTransfer.widget || {};

contentTransfer.widget.PageNameFilter = function( cfg ) {
    cfg = cfg || {};
    cfg.icon = 'search';

    contentTransfer.widget.PageNameFilter.parent.call( this, cfg );
};

OO.inheritClass( contentTransfer.widget.PageNameFilter, OO.ui.TextInputWidget );