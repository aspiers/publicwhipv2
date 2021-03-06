<?php

# $Id: division-forum.php,v 1.4 2008/01/09 17:16:02 publicwhip Exp $
# vim:sw=4:ts=4:et:nowrap

# The Public Whip, Copyright (C) 2003 Francis Irving and Julian Todd
# This is free software, and you are welcome to redistribute it under
# certain conditions.  However, it comes with ABSOLUTELY NO WARRANTY.
# For details see the file LICENSE.html in the top level of the source.
require_once __DIR__."/common.inc";
require_once __DIR__."/db.inc";
require_once __DIR__."/decodeids.inc";
require_once __DIR__."/account/user.inc";
require_once __DIR__."/database.inc";
require_once __DIR__."/divisionvote.inc";
require_once __DIR__."/forummagic.inc";
require_once __DIR__."/pretty.inc";

$db = new DB();

# decode the attributes
$divattr = get_division_attr_decode( '');
if ($divattr == "none")
    trigger_error('Division not found', E_USER_ERROR);
$motion_data = get_wiki_current_value($db, "motion", array($divattr["division_date"], $divattr["division_number"], $divattr['house']));
$name = extract_title_from_wiki_text($motion_data['text_body']);
$description = extract_motion_text_from_wiki_text($motion_data['text_body']);

$just_logged_in = do_login_screen();
if ($just_logged_in) {
    header("Location: ".$_SERVER['REQUEST_URI']."\n");
}

# Find discuss URL
$discuss_url = divisionvote_post_forum_link($db, $divattr['division_date'], $divattr['division_number'], $divattr['house']);
if (!$discuss_url) {
    if (user_isloggedin()) { 
        // First time someone logged in comes along, add division to the forum
        global $domain_name;
        divisionvote_post_forum_action($db, $divattr['division_date'], $divattr['division_number'], $divattr['house'],
            "Division introduced to forum.\n\n[b]Title:[/b] [url=https://$domain_name/division.php?date=".$divattr['division_date']."&number=".$divattr['division_number']."&house=".$divattr['house']."]".
            $name."[/url]\n[b]Description:[/b] ".trim($description));
        $discuss_url = divisionvote_post_forum_link($db, $divattr['division_date'], $divattr['division_number'], $divattr['house']);
    } else {
        login_screen();
        exit;
    }
}
header("Location: $discuss_url\n");

