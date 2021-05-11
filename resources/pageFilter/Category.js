window.contentTransfer = window.contentTransfer || {};
window.contentTransfer.widget = window.contentTransfer.widget || {};

contentTransfer.widget.CategoryFilter = function( cfg ) {
    contentTransfer.widget.CategoryFilter.parent.call( this, cfg );
};

OO.inheritClass( contentTransfer.widget.CategoryFilter, contentTransfer.widget.ComboBoxFilter );

contentTransfer.widget.CategoryFilter.prototype.getValueKey = function() {
    return 'text';
}