<?php
/**
 * SiteMetrics extension - displays statistics about social tools for
 * privileged users.
 *
 * @file
 * @ingroup Extensions
 * @author Aaron Wright <aaron.wright@gmail.com>
 * @author David Pean <david.pean@gmail.com>
 * @author Jack Phoenix <jack@countervandalism.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @link https://www.mediawiki.org/wiki/Extensions:SiteMetrics Documentation
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	die();
}

// Extension credits that will show up on Special:Version
$wgExtensionCredits['specialpage'][] = array(
	'path' => __FILE__,
	'name' => 'SiteMetrics',
	'version' => '1.3.0',
	'author' => array( 'Aaron Wright', 'David Pean', 'Jack Phoenix' ),
	'descriptionmsg' => 'sitemetrics-desc',
	'url' => 'https://www.mediawiki.org/wiki/Extension:SiteMetrics',
);

// Set up the new special page
$wgMessagesDirs['SiteMetrics'] = __DIR__ . '/i18n';
$wgAutoloadClasses['SiteMetrics'] = __DIR__ . '/SpecialSiteMetrics.php';
$wgSpecialPages['SiteMetrics'] = 'SiteMetrics';

// New user right, required to use Special:SiteMetrics
$wgAvailableRights[] = 'metricsview';
$wgGroupPermissions['sysop']['metricsview'] = true;
$wgGroupPermissions['staff']['metricsview'] = true;

// ResourceLoader support for MediaWiki 1.17+
$wgResourceModules['ext.siteMetrics'] = array(
	'styles' => 'SiteMetrics.css',
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'SiteMetrics',
	'position' => 'top' // available since r85616
);
