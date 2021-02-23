# GENERAL SETUP OF TARGETS

In order to set up this extension we need to wikis - one to push from and one to push to.

This extension needs to be installed on pushing wiki, and configured like so:

    $wgContentTransferTargets = [ 'privatewikiname' => [
        "url" => "http://target/api.php", // URL to the target wiki's API endpoint
        "user" => "Uname@bot", // Bot username
        "password" => "dvauaeersp02ds6s8n88bbrsj3asuuk", // Bot password
        "draftNamespace" => "Draft", // Name for the NS to be used as draft ("Draft" is automatically created by "MergeArticles" ext)
        "pushToDraft" => true // Whether to push to draft. If false will push directly to target pages
    ] ];


If wiki is non-BS (non-role system):

    $wgGroupPermissions['*']['content-transfer'] = false;
    $wgGroupPermissions['sysop']['content-transfer'] = true;

If certificate of target is not valid, you can set curl --insecure option, and thus ignore it by:

    $wgContentTransferIgnoreInsecureSSL = true;    

WHEN SPECIFYING TARGET URL, IT MUST NOT BE LOCALHOST, BUT AN ACTUAL DOMAIN!!!

On receiving wiki, create a Bot account, and enter the user/pass to the configuration on pushing wiki.
Also, if planning on using Merge funcitonality, MergeArticles extension needs to be installed on receiving wiki