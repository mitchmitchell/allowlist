<?php
/**
 * Copyright Sangoma Technologies, Inc 2018
 * Copyright Magnolia Manor Networks 2021
 */

function allowlist_get_config($engine) {
    //Handled in class
}

function allowlist_allowlist_add($fc) {
	return FreePBX::Allowlist()->numberAdd($fc);
}

function allowlist_allowlist_remove($fc) {
	return FreePBX::Allowlist()->numberDelete($fc);
}

function allowlist_allowlist_last($fc) {
	return FreePBX::Allowlist()->getAllowlist();
}

function allowlist_list() {
	return FreePBX::Allowlist()->getAllowlist();
}

function allowlist_del($number){
	return FreePBX::Allowlist()->numberDel($number);
}

function allowlist_add($post){
	return FreePBX::Allowlist()->numberAdd($post);
}

// ensures post vars is valid
function allowlist_chk($post){
	return FreePBX::Allowlist()->checkPost($post);
}
