window.contentTransfer = window.contentTransfer || {};
window.contentTransfer.widget = window.contentTransfer.widget || {};

contentTransfer.widget.NamespaceFilter = function ( cfg ) {
	contentTransfer.widget.NamespaceFilter.parent.call( this, cfg );
};

OO.inheritClass( contentTransfer.widget.NamespaceFilter, contentTransfer.widget.ComboBoxFilter );
