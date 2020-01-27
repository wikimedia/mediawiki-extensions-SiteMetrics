<?php
/**
 * A special page for tracking usage of different kinds of social features.
 *
 * @file
 * @ingroup Extensions
 * @author Aaron Wright <aaron.wright@gmail.com>
 * @author David Pean <david.pean@gmail.com>
 * @author Jack Phoenix
 * @license GPL-2.0-or-later
 * @link https://www.mediawiki.org/wiki/Extensions:SiteMetrics Documentation
 */
use Wikimedia\Rdbms\IResultWrapper;

class SiteMetrics extends SpecialPage {

	/**
	 * Constructor -- set up the new special page
	 */
	public function __construct() {
		parent::__construct( 'SiteMetrics', 'metricsview' );
	}

	function formatDate( $date ) {
		$date_array = explode( ' ', $date );

		$year = $date_array[0];
		$month = $date_array[1];

		$time = mktime( 0, 0, 0, $month, 1, '20' . $year );
		return date( 'm', $time ) . '/' . date( 'y', $time );
	}

	function formatDateDay( $date ) {
		$date_array = explode( ' ', $date );

		$year = $date_array[0];
		$month = $date_array[1];
		$day = $date_array[2];

		$time = mktime( 0, 0, 0, $month, $day, '20' . $year );
		return date( 'm', $time ) . '/' . date( 'd', $time ) . '/' . date( 'y', $time );
	}

	function displayChart( $stats ) {
		// reverse stats array so that chart outputs correctly
		$reversed_stats = array_reverse( $stats );

		// determine the maximum count
		$max = 0;
		for ( $x = 0; $x <= count( $reversed_stats ) - 1; $x++ ) {
			if ( $reversed_stats[$x]['count'] > $max ) {
				$max = $reversed_stats[$x]['count'];
			}
		}

		// Write Google Charts API script to generate graph
		$output = "<script type=\"text/javascript\">

		var simpleEncoding = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		var maxValue = '{$max}';
		var valueArray = new Array(";

		$first_date = '';
		$last_date = '';
		for ( $x = 0; $x <= count( $reversed_stats ) - 1; $x++ ) {
			// get first and last dates
			if ( $x == 0 ) {
				$first_date = $reversed_stats[$x]['date'];
			}
			if ( $x == count( $stats ) - 1 ) {
				$last_date = $reversed_stats[$x]['date'];
			}

			// make value array for Charts API
			$output .= $reversed_stats[$x]['count'];
			if ( $x != count( $stats ) - 1 ) {
				$output .= ',';
			}
		}

		$output .= ");

		function simpleEncode( valueArray, maxValue ) {
			var chartData = ['s:'];
			for ( var i = 0; i < valueArray.length; i++ ) {
				var currentValue = valueArray[i];
				if ( !isNaN( currentValue ) && currentValue >= 0 ) {
					chartData.push( simpleEncoding.charAt( Math.round( ( simpleEncoding.length - 1 ) * currentValue / maxValue ) ) );
				} else {
					chartData.push('_');
				}
			}
			return chartData.join('');
		}

		imgSrc = '<img src=\"http://chart.apis.google.com/chart?chs=400x200&amp;cht=lc&amp;chd='+simpleEncode(valueArray,maxValue)+'&amp;chco=ff0000&amp;chg=20,50,1,5&amp;chxt=x,y&amp;chxl=0:|{$first_date}|{$last_date}|1:||" . number_format( $max ) . "\"/>';

		document.write( imgSrc );

		</script>";

		return $output;
	}

	/**
	 * @param string $title Title - what kind of stats are we viewing?
	 * @param IResultWrapper $res Result wrapper object
	 * @param string $type 'day' for daily stats, 'month' for monthly stats
	 * @return string
	 */
	function displayStats( $title, $res, $type ) {
		$dbr = wfGetDB( DB_REPLICA );

		// build stats array
		$stats = [];
		foreach ( $res as $row ) {
			if ( $type == 'month' ) {
				$stats[] = [
					'date' => $this->formatDate( $row->the_date ),
					'count' => $row->the_count
				];
			} elseif ( $type == 'day' ) {
				$stats[] = [
					'date' => $this->formatDateDay( $row->the_date ),
					'count' => $row->the_count
				];
			}
		}

		$output = '';
		$output .= "<h3>{$title}</h3>";

		$output .= $this->displayChart( $stats );

		$output .= '<table class="smt-table">
			<tr class="smt-header">
				<td>' . $this->msg( 'sitemetrics-date' )->plain() . '</td>
				<td>' . $this->msg( 'sitemetrics-count' )->plain() . '</td>
				<td>' . $this->msg( 'sitemetrics-difference' )->plain() . '</td>
			</tr>';

		$lang = $this->getLanguage();

		for ( $x = 0; $x <= count( $stats ) - 1; $x++ ) {
			$diff = '';
			if ( $x != count( $stats ) - 1 ) {
				$diff = $stats[$x]['count'] - $stats[$x + 1]['count'];
				if ( $diff > 0 ) {
					$diff = "+{$diff}";
				} else {
					$diff = "{$diff}";
				}
			}
			$output .= "<tr>
					<td>{$stats[$x]['date']}</td>
					<td>" . $lang->formatNum( $stats[$x]['count'] ) . "</td>
					<td>{$diff}</td>
				</tr>";
		}

		$output .= '</table>';

		return $output;
	}

	/**
	 * Show the special page
	 *
	 * @param mixed|null $par Parameter passed to the page or null
	 */
	public function execute( $par ) {
		global $wgRegisterTrack;

		$out = $this->getOutput();
		$user = $this->getUser();
		$registry = ExtensionRegistry::getInstance();

		// Check the user is allowed to access this page
		$this->checkPermissions();

		// If user is blocked, s/he doesn't need to access this page
		if ( $user->isBlocked() ) {
			throw new UserBlockedError( $user->getBlock() );
		}

		$output = '';

		// Add CSS
		$out->addModuleStyles( 'ext.siteMetrics' );

		$statistic = $this->getRequest()->getVal( 'stat' );
		$pageTitle = ''; // page title, will be set later for each diff. query
		// This is required to make Special:SiteMetrics/param work...
		if ( !isset( $statistic ) ) {
			if ( $par ) {
				$statistic = $par;
			} else {
				$statistic = 'Edits';
			}
		}
		// An odd fix to make links like [[Special:SiteMetrics/Wall Messages]]
		// work properly...
		$statistic = str_replace( [ '_', '%20' ], ' ', $statistic );

		$statLink = SpecialPage::getTitleFor( 'SiteMetrics' );

		$dbr = wfGetDB( DB_REPLICA );

		$isPostgreSQL = ( $dbr->getType() === 'postgres' );
		// Note: MySQL/MariaDB %y %m %d arguments to DATE_FORMAT() produce output like
		// "20 01 15" for 15 January 2020.
		// The PostgreSQL equivalent for DATE_FORMAT(field, '%y %m %d') is TO_CHAR(field, 'yy mm dd')
		// The duplicate SQL queries below could probably be cleaned up by creating a function
		// which outputs either DATE_FORMAT or TO_CHAR in the desired output format,
		// depending on whether we're on MySQL/MariaDB or PostgreSQL
		$output .= '<div class="sm-navigation">
				<h2>' . $this->msg( 'sitemetrics-content-header' ) . '</h2>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Edits' ) ) . '">' . $this->msg( 'sitemetrics-edits' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Main Namespace Edits' ) ) . '">' . $this->msg( 'sitemetrics-main-ns' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=New Main Namespace Articles' ) ) . '">' . $this->msg( 'sitemetrics-new-articles' )->plain() . '</a>';
				// On March 26, 2010: these stats don't seem to be existing and
				// will only be confusing to end users, so I'm disabling them for now.
				// <a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Users Greater Than 5 Edits' ) ) . '">' . $this->msg( 'sitemetrics-greater-5-edits' )->plain() . '</a>
				// <a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Users Greater Than 100 Edits' ) ) . '">' . $this->msg( 'sitemetrics-greater-100-edits' )->plain() . '</a>
		$output .= '<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Anonymous Edits' ) ) . '">' . $this->msg( 'sitemetrics-anon-edits' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Images' ) ) . '">' . $this->msg( 'sitemetrics-images' )->plain() . '</a>';
		if ( $registry->isLoaded( 'Video' ) ) {
			$output .= '<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Video' ) ) . '">' . $this->msg( 'sitemetrics-video' )->plain() . '</a>';
		}

		$output .= '<h2>' . $this->msg( 'sitemetrics-user-social-header' )->plain() . '</h2>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=New Users' ) ) . '">' . $this->msg( 'sitemetrics-new-users' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Avatar Uploads' ) ) . '">' . $this->msg( 'sitemetrics-avatars' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Profile Updates' ) ) . '">' . $this->msg( 'sitemetrics-profile-updates' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=User Page Edits' ) ) . '">' . $this->msg( 'sitemetrics-user-page-edits' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Friendships' ) ) . '">' . $this->msg( 'sitemetrics-friendships' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Foeships' ) ) . '">' . $this->msg( 'sitemetrics-foeships' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Gifts' ) ) . '">' . $this->msg( 'sitemetrics-gifts' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Wall Messages' ) ) . '">' . $this->msg( 'sitemetrics-wall-messages' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=User Talk Messages' ) ) . '">' . $this->msg( 'sitemetrics-talk-messages' )->plain() . '</a>

				<h2>' . $this->msg( 'sitemetrics-point-stats-header' ) . '</h2>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Awards' ) ) . '">' . $this->msg( 'sitemetrics-awards' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Honorific Advancements' ) ) . '">' . $this->msg( 'sitemetrics-honorifics' )->plain() . '</a>';

		// Only display links to casual game statistics if said extensions are
		// installed...
		if (
			$registry->isLoaded( 'QuizGame' ) ||
			$registry->isLoaded( 'PollNY' ) ||
			$registry->isLoaded( 'PictureGame' )
		) {
			$output .= '<h2>' . $this->msg( 'sitemetrics-casual-game-stats' ) . '</h2>';
			if ( $registry->isLoaded( 'PollNY' ) ) {
				$output .= '<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Polls Created' ) ) . '">' . $this->msg( 'sitemetrics-polls-created' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Polls Taken' ) ) . '">' . $this->msg( 'sitemetrics-polls-taken' )->plain() . '</a>';
			}
			if ( $registry->isLoaded( 'PictureGame' ) ) {
				$output .= '<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Picture Games Created' ) ) . '">' . $this->msg( 'sitemetrics-picgames-created' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Picture Games Taken' ) ) . '">' . $this->msg( 'sitemetrics-picgames-taken' )->plain() . '</a>';
			}
			if ( $registry->isLoaded( 'QuizGame' ) ) {
				$output .= '<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Quizzes Created' ) ) . '">' . $this->msg( 'sitemetrics-quizzes-created' )->plain() . '</a>
				<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Quizzes Taken' ) ) . '">' . $this->msg( 'sitemetrics-quizzes-taken' )->plain() . '</a>';
			}
		}

		// Show the "Blog and Voting Statistics" header only if at least some
		// of said features are enabled...
		if (
			$registry->isLoaded( 'BlogPage' ) || $dbr->tableExists( 'Vote' ) ||
			$dbr->tableExists( 'Comments' ) || $dbr->tableExists( 'user_email_track' )
		) {
			$output .= '<h2>' . $this->msg( 'sitemetrics-blog-stats-header' )->plain() . '</h2>';
		}
		if ( $registry->isLoaded( 'BlogPage' ) ) {
			$output .= '<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=New Blog Pages' ) ) . '">' . $this->msg( 'sitemetrics-new-blogs' )->plain() . '</a>';
		}
		if ( $dbr->tableExists( 'Vote' ) ) {
			$output .= '<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Votes and Ratings' ) ) . '">' . $this->msg( 'sitemetrics-votes' )->plain() . '</a>';
		}
		if ( $dbr->tableExists( 'Comments' ) ) {
			$output .= '<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Comments' ) ) . '">' . $this->msg( 'sitemetrics-comments' )->plain() . '</a>';
		}
		if ( $dbr->tableExists( 'user_email_track' ) && $registry->isLoaded( 'MiniInvite' ) ) {
			$output .= '<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Invitations to Read Blog Page' ) ) . '">' . $this->msg( 'sitemetrics-invites' )->plain() . '</a>';
		}

		// Again, show the "Viral Statistics" header only if registration/email
		// tracking is enabled
		if (
			$dbr->tableExists( 'user_register_track' ) && $wgRegisterTrack ||
			$dbr->tableExists( 'user_email_track' )
		) {
			$output .= '<h2>' . $this->msg( 'sitemetrics-viral-stats' )->plain() . '</h2>';
		}
		if ( $dbr->tableExists( 'user_email_track' ) ) {
			$output .= '<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=Contact Invites' ) ) . '">' . $this->msg( 'sitemetrics-contact-imports' )->plain() . '</a>';
		}
		// Only show the "User Recruits" link if
		// 1) the table user_register_track exists and
		// 2) registration tracking is enabled
		if ( $dbr->tableExists( 'user_register_track' ) && $wgRegisterTrack ) {
			$output .= '<a href="' . htmlspecialchars( $statLink->getFullURL( 'stat=User Recruits' ) ) . '">' . $this->msg( 'sitemetrics-user-recruits' )->plain() . '</a>';
		}
		$output .= '</div>
		<div class="sm-content">';

		if ( $statistic == 'Edits' ) {
			$pageTitle = $this->msg( 'sitemetrics-edits' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
				DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' ) AS the_date
				FROM {$dbr->tableName( 'revision' )}
				GROUP BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' )
				ORDER BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' ) DESC
				LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(rev_timestamp, 'yy mm') AS the_date
				FROM {$dbr->tableName( 'revision' )}
				GROUP BY TO_CHAR(rev_timestamp, 'yy mm')
				ORDER BY TO_CHAR(rev_timestamp, 'yy mm') DESC
				LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-total-edits-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'revision' )}
					GROUP BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' )
					ORDER BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(rev_timestamp, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'revision' )}
					GROUP BY TO_CHAR(rev_timestamp, 'yy mm dd')
					ORDER BY TO_CHAR(rev_timestamp, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-total-edits-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Main Namespace Edits' ) {
			$pageTitle = $this->msg( 'sitemetrics-main-ns' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'revision' )}
					INNER JOIN {$dbr->tableName( 'page' )} ON rev_page=page_id WHERE page_namespace=0
					GROUP BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' )
					ORDER BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' )
					DESC LIMIT 12;";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(rev_timestamp, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'revision' )}
					INNER JOIN {$dbr->tableName( 'page' )} ON rev_page=page_id WHERE page_namespace=0
					GROUP BY TO_CHAR(rev_timestamp, 'yy mm')
					ORDER BY TO_CHAR(rev_timestamp, 'yy mm') DESC
					LIMIT 12;";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-main-ns-edits-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'revision' )} INNER JOIN {$dbr->tableName( 'page' )} ON rev_page=page_id WHERE page_namespace=0
					GROUP BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' )
					ORDER BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' )
					DESC LIMIT 120;";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(rev_timestamp, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'revision' )} INNER JOIN {$dbr->tableName( 'page' )} ON rev_page=page_id WHERE page_namespace=0
					GROUP BY TO_CHAR(rev_timestamp, 'yy mm dd')
					ORDER BY TO_CHAR(rev_timestamp, 'yy mm dd') DESC
					LIMIT 120;";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-main-ns-edits-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'New Main Namespace Articles' ) {
			$pageTitle = $this->msg( 'sitemetrics-new-articles' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1) , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'page' )}
					WHERE page_namespace=0
					GROUP BY DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), '%y %m' )
					ORDER BY DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), '%y %m' ) DESC
					LIMIT 12;";
			} else {
				$sql = "SELECT COUNT(*) AS the_count,
					TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm') AS the_date
					FROM {$dbr->tableName( 'page' )}
					WHERE page_namespace=0
					GROUP BY TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm')
					ORDER BY TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm') DESC
					LIMIT 12;";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-new-articles-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1) , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'page' )}
					WHERE page_namespace=0
					GROUP BY DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), '%y %m %d' )
					ORDER BY DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), '%y %m %d' ) DESC
					LIMIT 120;";
			} else {
				$sql = "SELECT COUNT(*) AS the_count,
					TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'page' )}
					WHERE page_namespace=0
					GROUP BY TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm dd')
					ORDER BY TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm dd') DESC
					LIMIT 120;";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-new-articles-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Anonymous Edits' ) {
			global $wgActorTableSchemaMigrationStage;

			$pageTitle = $this->msg( 'sitemetrics-anon-edits' )->plain();

			$wherePart = ' WHERE rev_user = 0 ';
			if ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
				$wherePart = "INNER JOIN {$dbr->tableName( 'revision_actor_temp' )} ON revactor_rev = rev_id " .
					"INNER JOIN {$dbr->tableName( 'actor' )} ON actor_id = revactor_actor " .
					'WHERE actor_user IS NULL';
			}

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'revision' )}
					{$wherePart}
					GROUP BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' )
					ORDER BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(rev_timestamp, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'revision' )}
					{$wherePart}
					GROUP BY TO_CHAR(rev_timestamp, 'yy mm')
					ORDER BY TO_CHAR(rev_timestamp, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-anon-edits-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'revision' )}
					{$wherePart}
					GROUP BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' )
					ORDER BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(rev_timestamp, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'revision' )}
					{$wherePart}
					GROUP BY TO_CHAR(rev_timestamp, 'yy mm dd')
					ORDER BY TO_CHAR(rev_timestamp, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-anon-edits-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Images' ) {
			$pageTitle = $this->msg( 'sitemetrics-images' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(img_timestamp)), '%y %m') AS the_date
					FROM {$dbr->tableName( 'image' )}
					GROUP BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(img_timestamp)), '%y %m')
					ORDER BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(img_timestamp)), '%y %m') DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(img_timestamp, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'image' )}
					GROUP BY TO_CHAR(img_timestamp, 'yy mm')
					ORDER BY TO_CHAR(img_timestamp, 'yy mm') DESC
					LIMIT 12";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-images-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(img_timestamp)), '%y %m %d') AS the_date
					FROM {$dbr->tableName( 'image' )}
					GROUP BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(img_timestamp)), '%y %m %d')
					ORDER BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(img_timestamp)), '%y %m %d') DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(img_timestamp, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'image' )}
					GROUP BY TO_CHAR(img_timestamp, 'yy mm dd')
					ORDER BY TO_CHAR(img_timestamp, 'yy mm dd') DESC
					LIMIT 120";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-images-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Video' ) {
			$pageTitle = $this->msg( 'sitemetrics-video' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1) , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'page' )}
					WHERE page_namespace=400
					GROUP BY DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), '%y %m' )
					ORDER BY DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count,
					TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm') AS the_date
					FROM {$dbr->tableName( 'page' )}
					WHERE page_namespace=400
					GROUP BY TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm')
					ORDER BY TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm') DESC
					LIMIT 12";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-video-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1) , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'page' )}
					WHERE page_namespace=400
					GROUP BY DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), '%y %m %d' )
					ORDER BY DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count,
					TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'page' )}
					WHERE page_namespace=400
					GROUP BY TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm dd')
					ORDER BY TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm dd') DESC
					LIMIT 120";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-video-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'New Users' ) {
			$pageTitle = $this->msg( 'sitemetrics-new-users' )->plain();
			if ( $dbr->tableExists( 'user_register_track' ) && $wgRegisterTrack ) {
				if ( !$isPostgreSQL ) {
					$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `ur_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'user_register_track' )}
					GROUP BY DATE_FORMAT( `ur_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `ur_date` , '%y %m' ) DESC
					LIMIT 12";
				} else {
					$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(ur_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'user_register_track' )}
					GROUP BY TO_CHAR(ur_date, 'yy mm')
					ORDER BY TO_CHAR(ur_date, 'yy mm') DESC
					LIMIT 12";
				}
				$res = $dbr->query( $sql, __METHOD__ );
				$output .= $this->displayStats( $this->msg( 'sitemetrics-new-users-month' )->plain(), $res, 'month' );

				if ( !$isPostgreSQL ) {
					$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `ur_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'user_register_track' )}
					GROUP BY DATE_FORMAT( `ur_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `ur_date` , '%y %m %d' ) DESC
					LIMIT 120";
				} else {
					$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(ur_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'user_register_track' )}
					GROUP BY TO_CHAR(ur_date, 'yy mm dd')
					ORDER BY TO_CHAR(ur_date, 'yy mm dd') DESC
					LIMIT 120";
				}
				$res = $dbr->query( $sql, __METHOD__ );
				$output .= $this->displayStats( $this->msg( 'sitemetrics-new-users-day' )->plain(), $res, 'day' );
			} else { // normal new user stats for this wiki from new user log
				if ( !$isPostgreSQL ) {
					$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m %d') AS the_date
					FROM {$dbr->tableName( 'logging' )}
					WHERE log_type='newusers'
					GROUP BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m %d')
					ORDER BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m %d') DESC
					LIMIT 12";
				} else {
					$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(log_timestamp, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'logging' )}
					WHERE log_type='newusers'
					GROUP BY TO_CHAR(log_timestamp, 'yy mm dd')
					ORDER BY TO_CHAR(log_timestamp, 'yy mm dd') DESC
					LIMIT 12";
				}
				$res = $dbr->query( $sql, __METHOD__ );
				$output .= $this->displayStats( $this->msg( 'sitemetrics-new-users-month' )->plain(), $res, 'month' );

				if ( !$isPostgreSQL ) {
					$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m %d') AS the_date
					FROM {$dbr->tableName( 'logging' )}
					WHERE log_type='newusers'
					GROUP BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m %d')
					ORDER BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m %d') DESC
					LIMIT 120";
				} else {
					$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(log_timestamp, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'logging' )}
					WHERE log_type='newusers'
					GROUP BY TO_CHAR(log_timestamp, 'yy mm dd')
					ORDER BY TO_CHAR(log_timestamp, 'yy mm dd') DESC
					LIMIT 120";
				}
				$res = $dbr->query( $sql, __METHOD__ );
				$output .= $this->displayStats( $this->msg( 'sitemetrics-new-users-day' )->plain(), $res, 'day' );
			}
		} elseif ( $statistic == 'Avatar Uploads' ) {
			$pageTitle = $this->msg( 'sitemetrics-avatars' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m') AS the_date
					FROM {$dbr->tableName( 'logging' )}
					WHERE log_type='avatar'
					GROUP BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m')
					ORDER BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m') DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(log_timestamp, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'logging' )}
					WHERE log_type='avatar'
					GROUP BY TO_CHAR(log_timestamp, 'yy mm')
					ORDER BY TO_CHAR(log_timestamp, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-avatars-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m %d') AS the_date
					FROM {$dbr->tableName( 'logging' )}
					WHERE log_type='avatar'
					GROUP BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m %d')
					ORDER BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m %d') DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(log_timestamp, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'logging' )}
					WHERE log_type='avatar'
					GROUP BY TO_CHAR(log_timestamp, 'yy mm dd')
					ORDER BY TO_CHAR(log_timestamp, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-avatars-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Profile Updates' ) {
			$pageTitle = $this->msg( 'sitemetrics-profile-updates' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m') AS the_date
					FROM {$dbr->tableName( 'logging' )}
					WHERE log_type='profile'
					GROUP BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m')
					ORDER BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m') DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(log_timestamp, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'logging' )}
					WHERE log_type='profile'
					GROUP BY TO_CHAR(log_timestamp, 'yy mm')
					ORDER BY TO_CHAR(log_timestamp, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-profile-updates-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m %d') AS the_date
					FROM {$dbr->tableName( 'logging' )}
					WHERE log_type='profile'
					GROUP BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m %d')
					ORDER BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(log_timestamp)), '%y %m %d') DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(log_timestamp, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'logging' )}
					WHERE log_type='profile'
					GROUP BY TO_CHAR(log_timestamp, 'yy mm dd')
					ORDER BY TO_CHAR(log_timestamp, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-profile-updates-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Friendships' ) {
			$pageTitle = $this->msg( 'sitemetrics-friendships' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*)/2 AS the_count, DATE_FORMAT( `r_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'user_relationship' )}
					WHERE r_type=1
					GROUP BY DATE_FORMAT( `r_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `r_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*)/2 AS the_count, TO_CHAR(r_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'user_relationship' )}
					WHERE r_type=1
					GROUP BY TO_CHAR(r_date, 'yy mm')
					ORDER BY TO_CHAR(r_date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-friendships-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*)/2 AS the_count, DATE_FORMAT( `r_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'user_relationship' )}
					WHERE r_type=1
					GROUP BY DATE_FORMAT( `r_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `r_date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*)/2 AS the_count, TO_CHAR(r_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'user_relationship' )}
					WHERE r_type=1
					GROUP BY TO_CHAR(r_date, 'yy mm dd')
					ORDER BY TO_CHAR(r_date, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-friendships-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Foeships' ) {
			$pageTitle = $this->msg( 'sitemetrics-foeships' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*)/2 AS the_count, DATE_FORMAT( `r_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'user_relationship' )}
					WHERE r_type=2
					GROUP BY DATE_FORMAT( `r_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `r_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*)/2 AS the_count, TO_CHAR(r_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'user_relationship' )}
					WHERE r_type=2
					GROUP BY TO_CHAR(r_date, 'yy mm')
					ORDER BY TO_CHAR(r_date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-foeships-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*)/2 AS the_count, DATE_FORMAT( `r_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'user_relationship' )}
					WHERE r_type=2
					GROUP BY DATE_FORMAT( `r_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `r_date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*)/2 AS the_count, TO_CHAR(r_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'user_relationship' )}
					WHERE r_type=2
					GROUP BY TO_CHAR(r_date, 'yy mm dd')
					ORDER BY TO_CHAR(r_date, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-foeships-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Gifts' ) {
			$pageTitle = $this->msg( 'sitemetrics-gifts' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `ug_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'user_gift' )}
					GROUP BY DATE_FORMAT( `ug_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `ug_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(ug_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'user_gift' )}
					GROUP BY TO_CHAR(ug_date, 'yy mm')
					ORDER BY TO_CHAR(ug_date, 'yy mm') DESC
					LIMIT 12";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-gifts-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `ug_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'user_gift' )}
					GROUP BY DATE_FORMAT( `ug_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `ug_date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(ug_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'user_gift' )}
					GROUP BY TO_CHAR(ug_date, 'yy mm dd')
					ORDER BY TO_CHAR(ug_date, 'yy mm dd') DESC
					LIMIT 120";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-gifts-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Wall Messages' ) {
			$pageTitle = $this->msg( 'sitemetrics-wall-messages' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(ub_date)), '%y %m') AS the_date
					FROM {$dbr->tableName( 'user_board' )}
					GROUP BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(ub_date)), '%y %m')
					ORDER BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(ub_date)), '%y %m') DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(ub_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'user_board' )}
					GROUP BY TO_CHAR(ub_date, 'yy mm')
					ORDER BY TO_CHAR(ub_date, 'yy mm') DESC
					LIMIT 12";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-wall-messages-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(ub_date)), '%y %m %d') AS the_date
					FROM {$dbr->tableName( 'user_board' )}
					GROUP BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(ub_date)), '%y %m %d')
					ORDER BY DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP(ub_date)), '%y %m %d') DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(ub_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'user_board' )}
					GROUP BY TO_CHAR(ub_date, 'yy mm dd')
					ORDER BY TO_CHAR(ub_date, 'yy mm dd') DESC
					LIMIT 120";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-wall-messages-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'User Page Edits' ) {
			$pageTitle = $this->msg( 'sitemetrics-user-page-edits' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'revision' )}
					INNER JOIN {$dbr->tableName( 'page' )} ON rev_page=page_id
					WHERE page_namespace=2
					GROUP BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' )
					ORDER BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' ) DESC
					LIMIT 12;";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(rev_timestamp, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'revision' )}
					INNER JOIN {$dbr->tableName( 'page' )} ON rev_page=page_id
					WHERE page_namespace=2
					GROUP BY TO_CHAR(rev_timestamp, 'yy mm')
					ORDER BY TO_CHAR(rev_timestamp, 'yy mm') DESC
					LIMIT 12;";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-user-page-edits-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'revision' )}
					INNER JOIN {$dbr->tableName( 'page' )} ON rev_page=page_id
					WHERE page_namespace=2
					GROUP BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' )
					ORDER BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' ) DESC
					LIMIT 120;";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(rev_timestamp, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'revision' )}
					INNER JOIN {$dbr->tableName( 'page' )} ON rev_page=page_id
					WHERE page_namespace=2
					GROUP BY TO_CHAR(rev_timestamp, 'yy mm dd')
					ORDER BY TO_CHAR(rev_timestamp, 'yy mm dd') DESC
					LIMIT 120;";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-user-page-edits-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'User Talk Messages' ) {
			$pageTitle = $this->msg( 'sitemetrics-talk-messages' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'revision' )}
					INNER JOIN {$dbr->tableName( 'page' )} ON rev_page=page_id
					WHERE page_namespace=3
					GROUP BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' )
					ORDER BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m' ) DESC
					LIMIT 12;";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(rev_timestamp, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'revision' )}
					INNER JOIN {$dbr->tableName( 'page' )} ON rev_page=page_id
					WHERE page_namespace=3
					GROUP BY TO_CHAR(rev_timestamp, 'yy mm')
					ORDER BY TO_CHAR(rev_timestamp, 'yy mm') DESC
					LIMIT 12;";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-talk-messages-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'revision' )}
					INNER JOIN {$dbr->tableName( 'page' )} ON rev_page=page_id
					WHERE page_namespace=3
					GROUP BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' )
					ORDER BY DATE_FORMAT( FROM_UNIXTIME(UNIX_TIMESTAMP(rev_timestamp)), '%y %m %d' ) DESC
					LIMIT 120;";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(rev_timestamp, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'revision' )}
					INNER JOIN {$dbr->tableName( 'page' )} ON rev_page=page_id
					WHERE page_namespace=3
					GROUP BY TO_CHAR(rev_timestamp, 'yy mm dd')
					ORDER BY TO_CHAR(rev_timestamp, 'yy mm dd') DESC
					LIMIT 120;";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-talk-messages-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Polls Created' ) {
			$pageTitle = $this->msg( 'sitemetrics-polls-created' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `poll_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'poll_question' )}
					GROUP BY DATE_FORMAT( `poll_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `poll_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(poll_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'poll_question' )}
					GROUP BY TO_CHAR(poll_date, 'yy mm')
					ORDER BY TO_CHAR(poll_date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-polls-created-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `poll_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'poll_question' )}
					GROUP BY DATE_FORMAT( `poll_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `poll_date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(poll_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'poll_question' )}
					GROUP BY TO_CHAR(poll_date, 'yy mm dd')
					ORDER BY TO_CHAR(poll_date, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-polls-created-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Polls Taken' ) {
			$pageTitle = $this->msg( 'sitemetrics-polls-taken' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `pv_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'poll_user_vote' )}
					GROUP BY DATE_FORMAT( `pv_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `pv_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(pv_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'poll_user_vote' )}
					GROUP BY TO_CHAR(pv_date, 'yy mm')
					ORDER BY TO_CHAR(pv_date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-polls-taken-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `pv_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'poll_user_vote' )}
					GROUP BY DATE_FORMAT( `pv_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `pv_date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(pv_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'poll_user_vote' )}
					GROUP BY TO_CHAR(pv_date, 'yy mm dd')
					ORDER BY TO_CHAR(pv_date, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-polls-taken-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Picture Games Created' ) {
			$pageTitle = $this->msg( 'sitemetrics-picgames-created' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `pg_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'picturegame_images' )}
					GROUP BY DATE_FORMAT( `pg_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `pg_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(pg_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'picturegame_images' )}
					GROUP BY TO_CHAR(pg_date, 'yy mm')
					ORDER BY TO_CHAR(pg_date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-picgames-created-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `pg_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'picturegame_images' )}
					GROUP BY DATE_FORMAT( `pg_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `pg_date` , '%y %m %d' ) DESC
					LIMIT 6";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(pg_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'picturegame_images' )}
					GROUP BY TO_CHAR(pg_date, 'yy mm dd')
					ORDER BY TO_CHAR(pg_date, 'yy mm dd') DESC
					LIMIT 6";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-picgames-created-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Picture Games Taken' ) {
			$pageTitle = $this->msg( 'sitemetrics-picgames-taken' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `vote_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'picturegame_votes' )}
					GROUP BY DATE_FORMAT( `vote_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `vote_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(vote_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'picturegame_votes' )}
					GROUP BY TO_CHAR(vote_date, 'yy mm')
					ORDER BY TO_CHAR(vote_date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-picgames-taken-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `vote_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'picturegame_votes' )}
					GROUP BY DATE_FORMAT( `vote_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `vote_date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(vote_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'picturegame_votes' )}
					GROUP BY TO_CHAR(vote_date, 'yy mm dd')
					ORDER BY TO_CHAR(vote_date, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-picgames-taken-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Quizzes Created' ) {
			$pageTitle = $this->msg( 'sitemetrics-quizzes-created' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `q_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'quizgame_questions' )}
					GROUP BY DATE_FORMAT( `q_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `q_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(q_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'quizgame_questions' )}
					GROUP BY TO_CHAR(q_date, 'yy mm')
					ORDER BY TO_CHAR(q_date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-quizzes-created-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `q_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'quizgame_questions' )}
					GROUP BY DATE_FORMAT( `q_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `q_date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(q_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'quizgame_questions' )}
					GROUP BY TO_CHAR(q_date, 'yy mm dd')
					ORDER BY TO_CHAR(q_date, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-quizzes-created-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Quizzes Taken' ) {
			$pageTitle = $this->msg( 'sitemetrics-quizzes-taken' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `a_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'quizgame_answers' )}
					GROUP BY DATE_FORMAT( `a_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `a_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(a_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'quizgame_answers' )}
					GROUP BY TO_CHAR(a_date, 'yy mm')
					ORDER BY TO_CHAR(a_date, 'yy mm') DESC
					LIMIT 12";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-quizzes-taken-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `a_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'quizgame_answers' )}
					GROUP BY DATE_FORMAT( `a_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `a_date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(a_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'quizgame_answers' )}
					GROUP BY TO_CHAR(a_date, 'yy mm dd')
					ORDER BY TO_CHAR(a_date, 'yy mm dd') DESC
					LIMIT 120";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-quizzes-taken-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'New Blog Pages' ) {
			$pageTitle = $this->msg( 'sitemetrics-new-blogs' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1) , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'page' )}
					WHERE page_namespace=500
					GROUP BY DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), '%y %m' )
					ORDER BY DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), '%y %m' ) DESC
					LIMIT 12;";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm') AS the_date
					FROM {$dbr->tableName( 'page' )}
					WHERE page_namespace=500
					GROUP BY TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm')
					ORDER BY TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm') DESC
					LIMIT 12;";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-new-blogs-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1) , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'page' )}
					WHERE page_namespace=500
					GROUP BY DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), '%y %m %d' )
					ORDER BY DATE_FORMAT( (SELECT FROM_UNIXTIME( UNIX_TIMESTAMP(rev_timestamp) ) FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), '%y %m %d' ) DESC
					LIMIT 120;";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'page' )}
					WHERE page_namespace=500
					GROUP BY TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm dd')
					ORDER BY TO_CHAR((SELECT rev_timestamp FROM {$dbr->tableName( 'revision' )} WHERE rev_page=page_id ORDER BY rev_timestamp ASC LIMIT 1), 'yy mm dd') DESC
					LIMIT 120;";
			}

			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-new-blogs-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Votes and Ratings' ) {
			// @todo FIXME: this won't fly on PGSQL b/c PGSQL doesn't recognize the non-standard (initial
			// upper case) table name
			// @see https://phabricator.wikimedia.org/T153012 (though that is about Comments but VoteNY has the same problem)
			$pageTitle = $this->msg( 'sitemetrics-votes' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `Vote_Date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'Vote' )}
					GROUP BY DATE_FORMAT( `Vote_Date` , '%y %m' )
					ORDER BY DATE_FORMAT( `Vote_Date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(Vote_Date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'Vote' )}
					GROUP BY TO_CHAR(Vote_Date, 'yy mm')
					ORDER BY TO_CHAR(Vote_Date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-votes-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `Vote_Date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'Vote' )}
					GROUP BY DATE_FORMAT( `Vote_Date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `Vote_Date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(Vote_Date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'Vote' )}
					GROUP BY TO_CHAR(Vote_Date, 'yy mm dd')
					ORDER BY TO_CHAR(Vote_Date, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-votes-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Comments' ) {
			// @todo FIXME: this won't fly on PGSQL b/c PGSQL doesn't recognize the non-standard (initial
			// upper case) table name
			// @see https://phabricator.wikimedia.org/T153012
			$pageTitle = $this->msg( 'sitemetrics-comments' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `Comment_Date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'Comments' )}
					GROUP BY DATE_FORMAT( `Comment_Date` , '%y %m' )
					ORDER BY DATE_FORMAT( `Comment_Date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(Comment_Date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'Comments' )}
					GROUP BY TO_CHAR(Comment_Date, 'yy mm')
					ORDER BY TO_CHAR(Comment_Date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-comments-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `Comment_Date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'Comments' )}
					GROUP BY DATE_FORMAT( `Comment_Date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `Comment_Date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(Comment_Date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'Comments' )}
					GROUP BY TO_CHAR(Comment_Date, 'yy mm dd')
					ORDER BY TO_CHAR(Comment_Date, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-comments-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Contact Invites' ) {
			$pageTitle = $this->msg( 'sitemetrics-contact-imports' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT SUM(ue_count) AS the_count, DATE_FORMAT( `ue_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'user_email_track' )}
					WHERE ue_type IN (1,2,3)
					GROUP BY DATE_FORMAT( `ue_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `ue_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT SUM(ue_count) AS the_count, TO_CHAR(ue_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'user_email_track' )}
					WHERE ue_type IN (1,2,3)
					GROUP BY TO_CHAR(ue_date, 'yy mm')
					ORDER BY TO_CHAR(ue_date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-contact-invites-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT SUM(ue_count) AS the_count, DATE_FORMAT( `ue_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'user_email_track' )}
					WHERE ue_type IN (1,2,3)
					GROUP BY DATE_FORMAT( `ue_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `ue_date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT SUM(ue_count) AS the_count, TO_CHAR(ue_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'user_email_track' )}
					WHERE ue_type IN (1,2,3)
					GROUP BY TO_CHAR(ue_date, 'yy mm dd')
					ORDER BY TO_CHAR(ue_date, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-contact-invites-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Invitations to Read Blog Page' ) {
			$pageTitle = $this->msg( 'sitemetrics-invites' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT SUM(ue_count) AS the_count, DATE_FORMAT( `ue_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'user_email_track' )}
					WHERE ue_type = 4
					GROUP BY DATE_FORMAT( `ue_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `ue_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT SUM(ue_count) AS the_count, TO_CHAR(ue_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'user_email_track' )}
					WHERE ue_type = 4
					GROUP BY TO_CHAR(ue_date, 'yy mm')
					ORDER BY TO_CHAR(ue_date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-invites-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT SUM(ue_count) AS the_count, DATE_FORMAT( `ue_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'user_email_track' )}
					WHERE ue_type = 4
					GROUP BY DATE_FORMAT( `ue_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `ue_date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT SUM(ue_count) AS the_count, TO_CHAR(ue_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'user_email_track' )}
					WHERE ue_type = 4
					GROUP BY TO_CHAR(ue_date, 'yy mm dd')
					ORDER BY TO_CHAR(ue_date, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-invites-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'User Recruits' ) {
			$pageTitle = $this->msg( 'sitemetrics-user-recruits' );
			// @todo FIXME: update the queries for the actor stuff once that's merged in NewSignupPage
			// prolly should be something like ur_actor_referral IS NOT NULL
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `ur_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'user_register_track' )}
					WHERE ur_user_id_referral <> 0
					GROUP BY DATE_FORMAT( `ur_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `ur_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(ur_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'user_register_track' )}
					WHERE ur_user_id_referral <> 0
					GROUP BY TO_CHAR(ur_date, 'yy mm')
					ORDER BY TO_CHAR(ur_date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-user-recruits-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count, DATE_FORMAT( `ur_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'user_register_track' )}
					WHERE ur_user_id_referral <> 0
					GROUP BY DATE_FORMAT( `ur_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `ur_date` , '%y %m %d' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(ur_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'user_register_track' )}
					WHERE ur_user_id_referral <> 0
					GROUP BY TO_CHAR(ur_date, 'yy mm dd')
					ORDER BY TO_CHAR(ur_date, 'yy mm dd') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-user-recruits-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Awards' ) {
			$pageTitle = $this->msg( 'sitemetrics-awards' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( `sg_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'user_system_gift' )}
					GROUP BY DATE_FORMAT( `sg_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `sg_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(sg_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'user_system_gift' )}
					GROUP BY TO_CHAR(sg_date, 'yy mm')
					ORDER BY TO_CHAR(sg_date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-awards-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( `sg_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'user_system_gift' )}
					GROUP BY DATE_FORMAT( `sg_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `sg_date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(sg_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'user_system_gift' )}
					GROUP BY TO_CHAR(sg_date, 'yy mm dd')
					ORDER BY TO_CHAR(sg_date, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-awards-day' )->plain(), $res, 'day' );
		} elseif ( $statistic == 'Honorific Advancements' ) {
			$pageTitle = $this->msg( 'sitemetrics-honorifics' )->plain();
			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( `um_date` , '%y %m' ) AS the_date
					FROM {$dbr->tableName( 'user_system_messages' )}
					GROUP BY DATE_FORMAT( `um_date` , '%y %m' )
					ORDER BY DATE_FORMAT( `um_date` , '%y %m' ) DESC
					LIMIT 12";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(um_date, 'yy mm') AS the_date
					FROM {$dbr->tableName( 'user_system_messages' )}
					GROUP BY TO_CHAR(um_date, 'yy mm')
					ORDER BY TO_CHAR(um_date, 'yy mm') DESC
					LIMIT 12";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-honorifics-month' )->plain(), $res, 'month' );

			if ( !$isPostgreSQL ) {
				$sql = "SELECT COUNT(*) AS the_count,
					DATE_FORMAT( `um_date` , '%y %m %d' ) AS the_date
					FROM {$dbr->tableName( 'user_system_messages' )}
					GROUP BY DATE_FORMAT( `um_date` , '%y %m %d' )
					ORDER BY DATE_FORMAT( `um_date` , '%y %m %d' ) DESC
					LIMIT 120";
			} else {
				$sql = "SELECT COUNT(*) AS the_count, TO_CHAR(um_date, 'yy mm dd') AS the_date
					FROM {$dbr->tableName( 'user_system_messages' )}
					GROUP BY TO_CHAR(um_date, 'yy mm dd')
					ORDER BY TO_CHAR(um_date, 'yy mm dd') DESC
					LIMIT 120";
			}
			$res = $dbr->query( $sql, __METHOD__ );
			$output .= $this->displayStats( $this->msg( 'sitemetrics-honorifics-day' )->plain(), $res, 'day' );
		}

		$output .= '</div>';

		// Set page title here, we can't do it earlier
		$out->setPageTitle( $this->msg( 'sitemetrics-title', $pageTitle ) );

		$out->addHTML( $output );
	}

}
