<?php
if(!IS_LOGGED) {
	header("Location: $site_url/404");
    exit;
}
if ($music->config->store_system != 'on' || $music->user->artist == 0) {
	header("Location: $site_url/404");
    exit;
}
$music->site_title = lang("Create Product") . ' | ' . $music->config->title;
$music->site_description = $music->config->description;
$music->site_pagename = "create-product";
$music->site_content     = loadPage('create-product/content');