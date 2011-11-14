<?php
/*
** Zabbix
** Copyright (C) 2000-2011 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
**/
?>
<?php

/**
 * In case gettext functions do not exist, just replacing them with our own,
 * so user can see atleast english translation
 */

if (!function_exists('_')) {
	function _($string) {
		return $string;
	}
}

if (!function_exists('gettext')) {
	function gettext($string) {
		return $string;
	}
}

if (!function_exists('ngettext')) {
	function ngettext($string1, $string2, $n) {
		return $n == 1 ? $string1 : $string2;
	}
}

if (!function_exists('pgettext')) {
	function pgettext($context, $msgid) {
		$contextString = $context."\004".$msgid;
		$translation = _($contextString);
		return ($translation == $contextString) ? $msgid : $translation;
	}
}

if (!function_exists('npgettext')) {
	function npgettext($context, $msgid, $msgid_plural, $num) {
		$contextString = $context."\004".$msgid;
		$contextStringp = $context."\004".$msgid_plural;
		$translation = ngettext($contextString, $contextStringp, $num);
		if ($translation == $contextString || $translation == $contextStringp) {
			return $msgid;
		}
		else {
			return $translation;
		}
	}
}

function _s($string) {
	$arguments = array_slice(func_get_args(), 1);
	return vsprintf(_($string), $arguments);
}

function _n($string1, $string2, $value) {
	$arguments = array_slice(func_get_args(), 2);
	return vsprintf(ngettext($string1, $string2, $value), $arguments);
}

/**
 * Translates the string with respect to the given context and replace placeholders with supplied arguments.
 * If no translation is found, the original string will be used. Unlimited number of parameters supplied.
 *
 * Example: _x('context', 'Message for arg1 "%1$s" and arg2 "%2$s"', 'arg1Value', 'arg2Value');
 * returns: 'Message for arg1 "arg1Value" and arg2 "arg2Value"'
 *
 * @param string $context   Context of the string
 * @param string $message   String to translate
 *
 * @return string
 */
function _x($context, $message) {
	$arguments = array_slice(func_get_args(), 2);

	if ($context == '') {
		return vsprintf($message, $arguments);
	}
	else {
		return vsprintf(pgettext($context, $message), $arguments);
	}
}

/**
 * Translates the string with respect to the given context and plural forms also replace placeholders with supplied arguments.
 * If no translation is found, the original string will be used. Unlimited number of parameters supplied.
 *
 * Example: _xn('context', '%1$s message for arg1 "%2$s"', '%1$s messages for arg1 "%2$s"', 3, 'arg1Value');
 * returns: '3 messagges for arg1 "arg1Value"'
 *
 * @param string $context          context of the string
 * @param string $message          string to translate
 * @param string $message_plural   string to translate for plural form
 * @param int    $num              number to determine usage of plural form, also is used as first replace argument
 *
 * @return string
 */
function _xn($context, $message, $message_plural, $num) {
	$arguments = array_slice(func_get_args(), 3);

	if ($context == '') {
		return vsprintf(ngettext($message, $message_plural, $num), $arguments);
	}
	else {
		return vsprintf(npgettext($context, $message, $message_plural, $num), $arguments);
	}
}

?>
