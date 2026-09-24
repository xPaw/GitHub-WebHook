<?php
declare(strict_types=1);

namespace GitHubWebHook;

class DiscordConverter extends BaseConverter
{
	// An unclosed comment hides the rest of the text, which is also how GitHub renders it
	private const string HTML_COMMENT = '~<!--.*?(?:-->|\z)~s';

	// Requires a tag name, so that a lone "<" in text such as "a < b" is left alone.
	// A tag never contains another "<", which keeps text full of unclosed tags cheap to scan.
	private const string HTML_TAG = '~</?[a-z](?:[^<>"\']|"[^"<]*"|\'[^\'<]*\')*>~i';

	// Something new, or something that went well
	private const int COLOR_DEFAULT = 5025616;
	// Something changed, which is neither good nor bad
	private const int COLOR_NEUTRAL = 3113463;
	// Something needs attention
	private const int COLOR_ATTENTION = 16750592;
	// Something was removed or rejected
	private const int COLOR_BAD = 16007990;
	// Something was finished
	private const int COLOR_CLOSED = 8540383;
	// Something was set aside
	private const int COLOR_SET_ASIDE = 7239297;

	// Discord turns down a webhook username holding either of these, in any case and anywhere in it,
	// so that nothing can pass itself off as Discord or as its own bot. Neither is documented,
	// and a GitHub login is allowed to contain both.
	private const string BLOCKED_USERNAME = '~discord|clyde~i';

	private const int TEXT_DISPLAY = 10;
	private const int CONTAINER = 17;
	// Turns the message into components, which take the place of content and embeds
	private const int IS_COMPONENTS_V2 = 32768;

	// Discord turns a message down when the text of all of its components adds up to more than this
	private const int MAX_MESSAGE_LENGTH = 4000;


	// A heading in a body would out-shout the title of the card it is in, so it becomes bold instead.
	// Discord takes a heading and subtext however far they are indented, and a tab after the hashes.
	private const string HEADING = '~^ *\#{1,6}\s+(\S.*)$~';
	// Subtext is smaller than body text, which is what the scope and the labels of a card are set in
	private const string SUBTEXT = '~^ *-\# +~';
	// Only a line that opens with a run of three backticks fences a block; anywhere else they are text
	private const string FENCE = '~^ {0,3}```~';

	// How many wrapped lines of a body are quoted as the body of a card
	private const int MAX_BODY_LINES = 8;
	// Roughly how many characters fit on one line beside the avatar. Body text is set in a proportional
	// font, so no count of characters really models where it wraps; this is an estimate at a usual width.
	private const int PER_LINE = 70;
	// Fenced code is monospace, and about four fifths the width of prose for it
	private const int CODE_PER_LINE = 55;

	// How much of the first line of a commit or wiki message is quoted
	private const int MAX_SHORT_MESSAGE = 100;

	/**
	 * Parses GitHub's webhook payload and returns a formatted message.
	 *
	 * @return array{
	 *     flags: int,
	 *     username?: string,
	 *     avatar_url?: string,
	 *     components: list<array{
	 *         type: int,
	 *         accent_color: int,
	 *         components: list<array{type: int, content: string}>,
	 *     }>,
	 * }
	 */
	public function GetEmbed( ) : array
	{
		switch( $this->EventType )
		{
			case 'ping'          : $Embed = $this->FormatPingEvent( ); break;
			case 'push'          : $Embed = $this->FormatPushEvent( ); break;
			case 'delete'        : $Embed = $this->FormatDeleteEvent( ); break;
			case 'discussion'    : $Embed = $this->FormatDiscussionEvent( ); break;
			case 'discussion_comment': $Embed = $this->FormatDiscussionCommentEvent( ); break;
			case 'public'        : $Embed = $this->FormatPublicEvent( ); break;
			case 'issues'        : $Embed = $this->FormatIssuesEvent( ); break;
			case 'member'        : $Embed = $this->FormatMemberEvent( ); break;
			case 'gollum'        : $Embed = $this->FormatGollumEvent( ); break;
			case 'package'       :
			case 'registry_package': $Embed = $this->FormatPackageEvent( ); break;
			case 'release'       : $Embed = $this->FormatReleaseEvent( ); break;
			case 'milestone'     : $Embed = $this->FormatMilestoneEvent( ); break;
			case 'repository'    : $Embed = $this->FormatRepositoryEvent( ); break;
			case 'pull_request'  : $Embed = $this->FormatPullRequestEvent( ); break;
			case 'issue_comment' : $Embed = $this->FormatIssueCommentEvent( ); break;
			case 'commit_comment': $Embed = $this->FormatCommitCommentEvent( ); break;
			case 'pull_request_review': $Embed = $this->FormatPullRequestReviewEvent( ); break;
			case 'pull_request_review_comment': $Embed = $this->FormatPullRequestReviewCommentEvent( ); break;
			case 'repository_advisory': $Embed = $this->FormatRepositoryAdvisoryEvent( ); break;
			case 'dependabot_alert': $Embed = $this->FormatDependabotAlertEvent( ); break;
			case 'code_scanning_alert': $Embed = $this->FormatCodeScanningAlertEvent( ); break;
			case 'secret_scanning_alert': $Embed = $this->FormatSecretScanningAlertEvent( ); break;
			case 'project'       : $Embed = $this->FormatProjectEvent( ); break;
			case 'projects_v2'   : $Embed = $this->FormatProjectV2Event( ); break;
			case 'projects_v2_status_update': $Embed = $this->FormatProjectStatusUpdateEvent( ); break;
			case 'branch_protection_configuration': $Embed = $this->FormatBranchProtectionConfigurationEvent( ); break;
			case 'branch_protection_rule': $Embed = $this->FormatBranchProtectionRuleEvent( ); break;
			case 'repository_ruleset': $Embed = $this->FormatRepositoryRulesetEvent( ); break;
			case 'deploy_key'    : $Embed = $this->FormatDeployKeyEvent( ); break;
			case 'meta'          : $Embed = $this->FormatMetaEvent( ); break;
			case 'organization'  : $Embed = $this->FormatOrganizationEvent( ); break;
			case 'org_block'     : $Embed = $this->FormatOrgBlockEvent( ); break;
			case 'membership'    : $Embed = $this->FormatMembershipEvent( ); break;
			case 'team'          : $Embed = $this->FormatTeamEvent( ); break;
			case 'sponsorship'   : $Embed = $this->FormatSponsorshipEvent( ); break;
			case 'workflow_run'  : $Embed = $this->FormatWorkflowRunEvent( ); break;
			default              : throw new NotImplementedException( $this->EventType );
		}

		/**
		 * What every event above is formatted into, before it is laid out as components.
		 *
		 * The title is the start of the sentence that the sender opens, which links to the event.
		 * Discord renders markdown in the text of a link but can not escape any of it there, so the title
		 * only ever holds our own words, numbers, logins and hashes, none of which have anything in them
		 * to escape. Whatever somebody else wrote is escaped and goes after the link, as the end of the title.
		 *
		 * @var array{
		 *     title: string,
		 *     titleEnd?: string,
		 *     url?: ?string,
		 *     color: int,
		 *     description?: ?string,
		 *     author: array{name: string, icon_url: string},
		 *     footer?: ?array{text: string},
		 * } $Embed
		 */

		// The sender opens the sentence the title finishes, and the start of it links to the event.
		// Who did it is in the card itself, so a message that can not be sent as them still says so.
		// A login is only letters, digits and hyphens, and the "[bot]" of an app is a pair of brackets
		// that the text of a link may hold, so the sender needs no escaping in there.
		$Name = $Embed[ 'author' ][ 'name' ];
		$End = $Embed[ 'titleEnd' ] ?? '';
		$URL = $Embed[ 'url' ] ?? null;
		$Heading = $URL === null
			? '### ' . self::Escape( $Name ) . " {$Embed[ 'title' ]}{$End}"
			: "### [{$Name} {$Embed[ 'title' ]}]({$URL}){$End}";

		// Several repositories usually share a webhook, so the card says where the event happened
		$Scope = $this->FormatScope();

		if( $Scope !== null )
		{
			$Heading = '-# ' . self::Escape( $Scope ) . "\n" . $Heading;
		}

		$Parts = [ $Heading ];

		if( isset( $Embed[ 'footer' ][ 'text' ] ) )
		{
			// Everything the layout did not compose itself is somebody else's text in a markdown line
			$Parts[] = '-# ' . self::Escape( $Embed[ 'footer' ][ 'text' ] );
		}

		if( is_string( $Embed[ 'description' ] ?? null ) && $Embed[ 'description' ] !== '' )
		{
			$Parts[] = $Embed[ 'description' ];
		}

		// Each part in turn takes what the ones before it left, so the body gives way to the heading
		// rather than the message being turned down for the two of them together
		$Room = self::MAX_MESSAGE_LENGTH;
		$Components = [];

		foreach( $Parts as $Part )
		{
			// An ellipsis of its own would say nothing, and would still be over the limit
			if( $Room < 1 )
			{
				break;
			}

			$Part = self::LimitLength( $Part, $Room );
			$Room -= mb_strlen( $Part );
			$Components[] = [ 'type' => self::TEXT_DISPLAY, 'content' => $Part ];
		}

		$Message = [ 'flags' => self::IS_COMPONENTS_V2 ];

		if( preg_match( self::BLOCKED_USERNAME, $Embed[ 'author' ][ 'name' ] ) !== 1 )
		{
			// Saying where the name is from keeps it from reading as a Discord account of the same name,
			// and it is what stops a sender called "everyone" or "here" being turned down as well
			$Message[ 'username' ] = $Embed[ 'author' ][ 'name' ] . ' on GitHub';
			$Message[ 'avatar_url' ] = $Embed[ 'author' ][ 'icon_url' ];
		}

		$Message[ 'components' ] = [
			[
				'type' => self::CONTAINER,
				'accent_color' => $Embed[ 'color' ],
				'components' => $Components,
			],
		];

		return $Message;
	}

	private static function LimitLength( string $Message, int $Limit ) : string
	{
		if( mb_strlen( $Message ) > $Limit )
		{
			// The ellipsis counts towards the limit
			$Message = mb_substr( $Message, 0, $Limit - 1 );

			// Do not leave half of an escaped character behind
			if( ( strlen( $Message ) - strlen( rtrim( $Message, '\\' ) ) ) % 2 === 1 )
			{
				$Message = substr( $Message, 0, -1 );
			}

			$Message .= '…';
		}

		return $Message;
	}

	/**
	 * The parts every alert of a security feature has in common. A new alert always needs attention,
	 * what happened to it afterwards is coloured like any other action.
	 *
	 * @return mixed[]
	 */
	private function AlertEmbed( string $Title, string $TitleEnd, string $Action ) : array
	{
		return [
			'title' => $Title,
			'titleEnd' => $TitleEnd,
			'url' => $this->Payload->alert->html_url,
			'color' => $Action === 'created' ? self::COLOR_ATTENTION : $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];
	}

	private static function EscapeCode( string $Message ) : string
	{
		// A run of backticks can only be inside of a code span that is delimited by a longer run
		preg_match_all( '/`+/', $Message, $Runs );

		if( $Runs[ 0 ] === [] )
		{
			return '`' . $Message . '`';
		}

		$Delimiter = str_repeat( '`', max( array_map( strlen( ... ), $Runs[ 0 ] ) ) + 1 );

		// A space keeps a backtick at either end of the message apart from the delimiter,
		// and Discord only drops the space that pads a code span when there is a backtick beside it
		$Start = preg_match( '/^ *`/', $Message ) === 1 ? ' ' : '';
		$End = preg_match( '/` *$/', $Message ) === 1 ? ' ' : '';

		return $Delimiter . $Start . $Message . $End . $Delimiter;
	}

	/**
	 * The layout escapes what it is given, so the names are passed on as they are.
	 *
	 * @param ?array<object> $Labels
	 *
	 * @return ?array{text: string}
	 */
	private static function LabelsFooter( ?array $Labels ) : ?array
	{
		if( empty( $Labels ) )
		{
			return null;
		}

		return [ 'text' => implode( ' · ', array_map( static fn( object $Label ) : string => $Label->name, $Labels ) ) ];
	}

	/**
	 * Escapes characters that Discord would otherwise interpret as markdown, everywhere but in a link
	 * of its own. Nothing can be escaped in the text of a masked link, so none of this may end up there.
	 */
	private static function Escape( string $Message ) : string
	{
		// Discord links a url wherever it starts, backslashes and all, and a browser reads those as slashes
		if( preg_match_all( '~(?:https?|steam)://[^\s<]+[^<.,:;"\'\]\s]~u', $Message, $URLs, PREG_OFFSET_CAPTURE ) === false )
		{
			return self::EscapeText( $Message );
		}

		$Escaped = '';
		$From = 0;

		foreach( $URLs[ 0 ] as [ $URL, $Offset ] )
		{
			$URL = self::TrimParenthesis( $URL );
			$Escaped .= self::EscapeText( substr( $Message, $From, $Offset - $From ) ) . $URL;
			$From = $Offset + strlen( $URL );
		}

		return $Escaped . self::EscapeText( substr( $Message, $From ) );
	}

	private static function EscapeText( string $Message ) : string
	{
		return str_replace( [
			'\\',   '*',  '|',  '`',  '[',  ']',  '(',  ')',  '<',  '>',  '_',  '~',
		], [
			'\\\\', '\*', '\|', '\`', '\[', '\]', '\(', '\)', '\<', '\>', '\_', '\~',
		], $Message );
	}

	/** Discord leaves a closing parenthesis out of a url when there is no opening one for it. */
	private static function TrimParenthesis( string $URL ) : string
	{
		$From = 0;

		for( $i = strlen( $URL ) - 1; $i >= 0 && $URL[ $i ] === ')'; $i-- )
		{
			$Open = strpos( $URL, '(', $From );

			if( $Open === false )
			{
				return substr( $URL, 0, -1 );
			}

			$From = $Open + 1;
		}

		return $URL;
	}

	/** Names what something used to be called, after a title that says what it is called now. */
	private static function FromSuffix( string $Name ) : string
	{
		return ' (from **' . self::Escape( $Name ) . '**)';
	}

	/** @return array{name: string, icon_url: string} */
	private function FormatAuthor() : array
	{
		return [
			'name' => $this->Payload->sender->login,
			// GitHub always sends an avatar, and its own url for one stands in if it ever does not
			'icon_url' => $this->Payload->sender->avatar_url ?? 'https://github.com/' . $this->Payload->sender->login . '.png',
		];
	}

	/**
	 * Names the repository of an event, or with an `@` the account of an event that has no repository:
	 * the organization, or the sponsored account of a sponsors listing, whose ping only knows who set the webhook up.
	 */
	private function FormatScope() : ?string
	{
		// The repository that GitHubWebHook stands in for events without one has no url
		if( isset( $this->Payload->repository->html_url ) )
		{
			return $this->Payload->repository->name;
		}

		$Account = $this->Payload->organization->login
			?? $this->Payload->sponsorship->sponsorable->login
			?? ( ( $this->Payload->hook->type ?? null ) === 'SponsorsListing' ? $this->Payload->sender->login ?? null : null );

		return $Account === null ? null : "@{$Account}";
	}

	private function FormatAction( ?string $Action = null ) : int
	{
		$Action ??= $this->Payload->action;

		switch( $Action )
		{
			case 'reintroduced':
			case 'at risk'    :
			case 'reopened'   : return self::COLOR_ATTENTION;

			case 'deleted'    :
			case 'removed'    :
			case 'blocked'    :
			case 'disabled'   :
			case 'off track'  :
			case 'publicly leaked':
			case 'unpublished':
			case 'failed'     :
			case 'timed out'  :
			case 'failed to start':
			case 'requested changes in':
			case 'closed without merging': return self::COLOR_BAD;

			case 'dismissed'  :
			case 'auto-dismissed':
			case 'converted to draft':
			case 'archived'   :
			case 'inactive'   :
			case 'closed as not planned': return self::COLOR_SET_ASIDE;

			case 'pinned'     :
			case 'unpinned'   :
			case 'locked'     :
			case 'unlocked'   :
			case 'transferred':
			case 'renamed'    :
			case 'edited'     :
			case 'unblocked'  :
			case 'changed category':
			case 'publicized' :
			case 'privatized' :
			case 'unarchived' :
			case 'enabled auto-merge':
			case 'updated'    : return self::COLOR_NEUTRAL;

			case 'closed'     :
			case 'merged'     :
			case 'complete'   : return self::COLOR_CLOSED;

			default           : return self::COLOR_DEFAULT;
		}
	}

	/**
	 * A body keeps its markdown, but nothing in it may out-shout the card it is quoted in.
	 * Fenced code is left alone, where a leading # is a comment rather than a heading.
	 */
	private static function Demote( string $Line ) : string
	{
		$Line = preg_replace_callback(
			self::HEADING,
			static fn( array $Match ) : string => '**' . str_replace( '*', '', $Match[ 1 ] ) . '**',
			$Line
		) ?? $Line;

		return preg_replace( self::SUBTEXT, '', $Line ) ?? $Line;
	}

	/**
	 * Formats a body of text (issue, release, comment…) for use as the body of a card: html stripped,
	 * blank lines collapsed, headings flattened and height limited.
	 *
	 * How tall a card gets is what there is to limit, so a body is measured in the lines it takes up
	 * once each of them has wrapped; a count of characters says very little about that. Which lines are
	 * fenced is worked out once here, because it decides all three of how wide they wrap, whether their
	 * markdown is left as written, and whether a cut has left a block open.
	 */
	private static function FormatBody( ?string $Message ) : string
	{
		$Message ??= '';
		$Message = preg_replace( self::HTML_COMMENT, '', $Message ) ?? $Message;
		$Message = preg_replace( self::HTML_TAG, '', $Message ) ?? $Message;
		$Message = str_replace( [ "\r", "\n\n" ], [ "", "\n" ], $Message );
		$Message = self::Trim( $Message );

		$Kept = [];
		$Used = 0;
		$Fenced = false;

		foreach( explode( "\n", $Message ) as $Line )
		{
			$Fence = preg_match( self::FENCE, $Line ) === 1;
			// Inside a block a leading # is a comment rather than a heading, and is quoted as written
			$Shown = $Fenced || $Fence ? $Line : self::Demote( $Line );
			$PerLine = $Fenced ? self::CODE_PER_LINE : self::PER_LINE;
			$Height = max( 1, (int)ceil( mb_strlen( $Shown ) / $PerLine ) );

			if( $Used + $Height > self::MAX_BODY_LINES )
			{
				$Room = ( self::MAX_BODY_LINES - $Used ) * $PerLine - 1;

				// Without room for even one character there is a line already kept to mark instead,
				// because the budget can only be used up by one
				if( $Room > 0 )
				{
					$Kept[] = mb_substr( $Shown, 0, $Room ) . '…';
				}
				else
				{
					$Kept[ count( $Kept ) - 1 ] .= '…';
				}

				break;
			}

			$Used += $Height;
			$Kept[] = $Shown;

			if( $Fence )
			{
				$Fenced = !$Fenced;
			}
		}

		// A cut inside a block would leave it open, and an open block swallows the rest of the card
		return implode( "\n", $Kept ) . ( $Fenced ? "\n```" : '' );
	}

	private static function ShortMessage( string $Message ) : string
	{
		$Message = self::Trim( $Message );
		$NewMessage = explode( "\n", $Message, 2 );
		$NewMessage = $NewMessage[ 0 ];

		if( mb_strlen( $NewMessage ) > self::MAX_SHORT_MESSAGE )
		{
			$NewMessage = mb_substr( $NewMessage, 0, self::MAX_SHORT_MESSAGE );
		}

		if( $NewMessage !== $Message )
		{
			// Tidy ellipsis
			if( mb_substr( $NewMessage, -3 ) === '...' )
			{
				$NewMessage = mb_substr( $NewMessage, 0, -3 ) . '…';
			}
			else if( !str_ends_with( $NewMessage, '…' ) )
			{
				$NewMessage .= '…';
			}
		}

		return self::Escape( $NewMessage );
	}

	/**
	 * Formats a push event.
	 *
	 * @return mixed[]
	 */
	private function FormatPushEvent( ) : array
	{
		$DistinctCommits = $this->GetDistinctCommits( );
		$Num = count( $DistinctCommits );
		$NewCommits = sprintf( '%d new commit%s', $Num, $Num === 1 ? '' : 's' );

		// A ref is named by whoever made it, so it goes after the link with anything else from the payload
		$Embed = [
			'title' => '',
			'titleEnd' => '',
			'url' => $this->Payload->compare,
			'color' => self::COLOR_DEFAULT,
			'author' => $this->FormatAuthor(),
		];

		if( $this->Payload->created )
		{
			if( str_starts_with( $this->Payload->ref, 'refs/tags/' ) )
			{
				$Embed[ 'title' ] = 'tagged';
				$Embed[ 'titleEnd' ] = " " . self::EscapeCode( $this->RefName ) . " at " . self::EscapeCode( $this->BaseRefName ?? $this->AfterSHA() );
			}
			else
			{
				$Embed[ 'title' ] = 'created';
				$Embed[ 'titleEnd' ] = " " . self::EscapeCode( $this->RefName );

				if( $this->BaseRefName !== null )
				{
					$Embed[ 'titleEnd' ] .= " from " . self::EscapeCode( $this->BaseRefName );
				}
				else if( $Num > 0 )
				{
					$Embed[ 'titleEnd' ] .= " at " . self::EscapeCode( $this->AfterSHA() );
				}

				if( $Num > 0 )
				{
					$Embed[ 'titleEnd' ] .= " (+{$NewCommits})";
				}
			}
		}
		else if( $this->Payload->deleted )
		{
			throw new NotImplementedException( $this->EventType, 'deleted (use DeleteEvent if needed)' );
		}
		else if( $this->Payload->forced )
		{
			$Embed[ 'title' ] = 'force-pushed';
			$Embed[ 'titleEnd' ] = " " . self::EscapeCode( $this->RefName ) . " from " . self::EscapeCode( $this->BeforeSHA() ) . " to " . self::EscapeCode( $this->AfterSHA() );
			$Embed[ 'color' ] = self::COLOR_BAD;
		}
		else if( $Num === 0 && count( $this->Payload->commits ) > 0 )
		{
			if( $this->BaseRefName !== null )
			{
				$Embed[ 'title' ] = 'merged';
				$Embed[ 'titleEnd' ] = " " . self::EscapeCode( $this->BaseRefName ) . " into " . self::EscapeCode( $this->RefName );
				$Embed[ 'color' ] = self::COLOR_CLOSED;
			}
			else
			{
				$Embed[ 'title' ] = 'fast-forwarded';
				$Embed[ 'titleEnd' ] = " " . self::EscapeCode( $this->RefName ) . " from " . self::EscapeCode( $this->BeforeSHA() ) . " to " . self::EscapeCode( $this->AfterSHA() );
				$Embed[ 'color' ] = self::COLOR_NEUTRAL;
			}
		}
		else
		{
			// Most pushes go to the default branch, so only other branches are worth naming
			$Embed[ 'title' ] = "pushed {$NewCommits}";
			$Embed[ 'titleEnd' ] = $this->IsDefaultBranch() ? '' : ' to ' . self::EscapeCode( $this->RefName );
		}

		if( $this->Payload->forced )
		{
			// GitHub supports displaying proper diffs for force pushes
			// but it only appears to work if the diff url has full hashes
			// so we construct the url ourselves, instead of using the url in the payload
			// Note: this uses ".." instead of "..." to force github to actually display changes between the commits
			// and not the entire diff of the force push
			$Embed[ 'url' ] = "{$this->Payload->repository->html_url}/compare/{$this->Payload->before}..{$this->Payload->after}";
		}
		else if( $Num === 1 )
		{
			// If there's only one distinct commit, link to it directly
			$Embed[ 'url' ] = $DistinctCommits[ 0 ]->url;
		}

		if( $Num > 0 )
		{
			$CommitMessages = [];
			$CommitsLimit = 15;

			// A push of a single commit has the room to say what the commit itself says,
			// everything past the summary that the line above it already carries
			$Message = $Num === 1 ? trim( $DistinctCommits[ 0 ]->message ) : '';
			$Newline = strpos( $Message, "\n" );
			$Body = $Newline === false ? '' : self::FormatBody( substr( $Message, $Newline + 1 ) );

			while( --$Num >= 0 && --$CommitsLimit >= 0 )
			{
				$DistinctCommit = $DistinctCommits[ $Num ];

				// Where the body follows, the summary no longer has to trail off into it
				$Summary = $Body === '' ? $DistinctCommit->message : substr( $Message, 0, $Newline );

				$Commit = "[" . self::EscapeCode( substr( $DistinctCommit->id, 0, 6 ) ) . "]({$DistinctCommit->url}) ";
				$Commit .= self::ShortMessage( $Summary );

				if( isset( $DistinctCommit->author->username ) )
				{
					if( $DistinctCommit->author->username !== $this->Payload->sender->login )
					{
						$Commit .= " - " . self::Escape( $DistinctCommit->author->username );
					}
				}
				else
				{
					$Commit .= " - *" . self::Escape( $DistinctCommit->author->name ?? 'unknown' ) . "*";
				}

				$CommitMessages[] = $Commit;
			}

			$Embed[ 'description' ] = implode( "\n", $CommitMessages );

			if( $Body !== '' )
			{
				$Embed[ 'description' ] .= "\n\n" . $Body;
			}
		}

		return $Embed;
	}

	/**
	 * Formats a deletion event.
	 *
	 * @return mixed[]
	 */
	private function FormatDeleteEvent( ) : array
	{
		if( $this->Payload->ref_type !== 'tag'
		&&  $this->Payload->ref_type !== 'branch' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->ref_type );
		}

		return [
			'title' => "deleted {$this->Payload->ref_type}",
			'titleEnd' => ' ' . self::EscapeCode( $this->Payload->ref ),
			'url' => $this->Payload->repository->html_url,
			'color' => self::COLOR_BAD,
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats an issue event.
	 *
	 * @return mixed[]
	 */
	private function FormatIssuesEvent( ) : array
	{
		$Action = $this->Payload->action;

		if( $Action === 'edited'
		||  $Action === 'unpinned'
		||  $Action === 'milestoned'
		||  $Action === 'demilestoned'
		||  $Action === 'labeled'
		||  $Action === 'unlabeled'
		||  $Action === 'assigned'
		||  $Action === 'unassigned'
		||  $Action === 'typed'
		||  $Action === 'untyped'
		||  $Action === 'field_added'
		||  $Action === 'field_removed' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $Action );
		}

		if( $Action !== 'opened'
		&&  $Action !== 'closed'
		&&  $Action !== 'reopened'
		&&  $Action !== 'deleted'
		&&  $Action !== 'pinned'
		&&  $Action !== 'locked'
		&&  $Action !== 'unlocked'
		&&  $Action !== 'transferred' )
		{
			throw new NotImplementedException( $this->EventType, $Action );
		}

		if( $Action === 'closed' && $this->Payload->issue->state_reason === 'not_planned' )
		{
			$Action = 'closed as not planned';
		}

		[ $Verb, $Suffix ] = self::ActionPhrase( $Action );

		$Embed = [
			'title' => "{$Verb} issue **#{$this->Payload->issue->number}**{$Suffix}",
			'titleEnd' => ': ' . self::Escape( $this->Payload->issue->title ),
			'url' => $this->Payload->issue->html_url,
			'color' => $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];

		if( $Action === 'opened' )
		{
			$Embed[ 'description' ] = self::FormatBody( $this->Payload->issue->body );

			$Embed[ 'footer' ] = self::LabelsFooter( $this->Payload->issue->labels ?? null );
		}

		return $Embed;
	}

	/**
	 * Formats a pull request event.
	 *
	 * @return mixed[]
	 */
	private function FormatPullRequestEvent( ) : array
	{
		$Action = $this->Payload->action;

		if( $Action === 'closed' )
		{
			if( $this->Payload->pull_request->merged === true )
			{
				$Action = 'merged';
			}
			else
			{
				$Action = 'closed without merging';
			}
		}
		else if( $Action === 'ready_for_review' )
		{
			$Action = 'readied';
		}
		else if( $Action === 'auto_merge_enabled' )
		{
			$Action = 'enabled auto-merge';
		}
		else if( $Action === 'converted_to_draft' )
		{
			$Action = 'converted to draft';
		}

		if( $Action === 'edited'
		||  $Action === 'synchronize'
		||  $Action === 'labeled'
		||  $Action === 'unlabeled'
		||  $Action === 'assigned'
		||  $Action === 'unassigned'
		||  $Action === 'review_requested'
		||  $Action === 'review_request_removed'
		||  $Action === 'milestoned'
		||  $Action === 'demilestoned'
		||  $Action === 'enqueued'
		||  $Action === 'dequeued'
		||  $Action === 'auto_merge_disabled'
		||  $Action === 'stacked' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $Action );
		}

		if( $Action !== 'opened'
		&&  $Action !== 'reopened'
		&&  $Action !== 'merged'
		&&  $Action !== 'locked'
		&&  $Action !== 'unlocked'
		&&  $Action !== 'readied'
		&&  $Action !== 'enabled auto-merge'
		&&  $Action !== 'converted to draft'
		&&  $Action !== 'closed without merging' )
		{
			throw new NotImplementedException( $this->EventType, $Action );
		}

		[ $Verb, $Suffix ] = self::ActionPhrase( $Action );
		$Draft = $this->Payload->pull_request->draft && $Action !== 'converted to draft' ? 'draft ' : '';

		$Embed = [
			'title' => "{$Verb} {$Draft}PR **#{$this->Payload->pull_request->number}**{$Suffix}",
			'titleEnd' => ': ' . self::Escape( $this->Payload->pull_request->title ),
			'url' => $this->Payload->pull_request->html_url,
			'color' => $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];

		if( $Action === 'opened' )
		{
			$Embed[ 'description' ] = self::FormatBody( $this->Payload->pull_request->body );
			$Embed[ 'footer' ] = self::LabelsFooter( $this->Payload->pull_request->labels ?? null );
		}
		else if( $Action === 'merged' )
		{
			$Embed[ 'description' ] = "Merged from **" . self::Escape( $this->Payload->pull_request->user->login ?? 'ghost' ) . "** to " . self::EscapeCode( $this->Payload->pull_request->base->ref );
		}

		return $Embed;
	}

	/**
	 * Formats a milestone event.
	 *
	 * @return mixed[]
	 */
	private function FormatMilestoneEvent( ) : array
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'opened'
		&&  $this->Payload->action !== 'closed'
		&&  $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		// A new milestone is "created", GitHub calls reopening a closed one "opened"
		$Action = $this->Payload->action === 'opened' ? 'reopened' : $this->Payload->action;

		return [
			'title' => "{$Action} milestone **#{$this->Payload->milestone->number}**",
			'titleEnd' => ': ' . self::Escape( $this->Payload->milestone->title ),
			'description' => $Action === 'created' ? self::FormatBody( $this->Payload->milestone->description ) : '',
			'url' => $this->Payload->milestone->html_url,
			'color' => $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a package event.
	 *
	 * @return mixed[]
	 */
	private function FormatPackageEvent( ) : array
	{
		if( $this->Payload->action !== 'published'
		&&  $this->Payload->action !== 'updated' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		// Both events have the same payload under a different name
		$Package = $this->Payload->registry_package ?? $this->Payload->package;
		$Body = $Package->package_version->body ?? null;
		$Version = $Package->package_version->version ?? '';

		return [
			// The type is one of a handful that GitHub names, such as npm or maven
			'title' => "{$this->Payload->action} " . strtolower( $Package->package_type ) . " package",
			'titleEnd' => ": **" . self::Escape( $Package->name ) . "**" . ( $Version === '' ? '' : ' ' . self::Escape( $Version ) ),
			// Container packages have an empty object as their body
			'description' => $this->Payload->action === 'published' && is_string( $Body ) ? self::FormatBody( $Body ) : '',
			'url' => $Package->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a release event.
	 *
	 * @return mixed[]
	 */
	private function FormatReleaseEvent( ) : array
	{
		if( $this->Payload->action === 'created'
		||  $this->Payload->action === 'edited'
		||  $this->Payload->action === 'released'
		||  $this->Payload->action === 'prereleased' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'published'
		&&  $this->Payload->action !== 'unpublished'
		&&  $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Name = $this->Payload->release->tag_name;

		if( ( $this->Payload->release->name ?? '' ) !== '' && $this->Payload->release->name !== $this->Payload->release->tag_name )
		{
			$Name .= " ({$this->Payload->release->name})";
		}

		return [
			'title' => "{$this->Payload->action} a " . ( $this->Payload->release->draft ? 'draft ' : '' ) . ( $this->Payload->release->prerelease ? 'pre-' : '' ) . "release",
			'titleEnd' => ': ' . self::Escape( $Name ),
			// Release notes are only worth showing when the release appears
			'description' => $this->Payload->action === 'published' ? self::FormatBody( $this->Payload->release->body ) : '',
			'url' => $this->Payload->release->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a commit comment event.
	 *
	 * @return mixed[]
	 */
	private function FormatCommitCommentEvent( ) : array
	{
		if( $this->Payload->action !== 'created' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return [
			'title' => "commented on commit " . self::EscapeCode( substr( $this->Payload->comment->commit_id, 0, 6 ) ),
			'description' => self::FormatBody( $this->Payload->comment->body ),
			'url' => $this->Payload->comment->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a issue comment event.
	 *
	 * @return mixed[]
	 */
	private function FormatIssueCommentEvent( ) : array
	{
		if( $this->Payload->action === 'edited'
		||  $this->Payload->action === 'pinned'
		||  $this->Payload->action === 'unpinned' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		$IsPullRequest = isset( $this->Payload->issue->pull_request );

		if( $this->Payload->action === 'created' )
		{
			return [
				'title' => "commented on " . ( $IsPullRequest ? 'PR' : 'issue' ) . " **#{$this->Payload->issue->number}**",
				'titleEnd' => ': ' . self::Escape( $this->Payload->issue->title ),
				'description' => self::FormatBody( $this->Payload->comment->body ),
				'url' => $this->Payload->comment->html_url,
				'color' => $this->FormatAction(),
				'author' => $this->FormatAuthor(),
			];
		}

		if( $this->Payload->action === 'deleted' )
		{
			return [
				'title' => "deleted comment in " . ( $IsPullRequest ? 'PR' : 'issue' ) . " **#{$this->Payload->issue->number}** from **" . ( $this->Payload->comment->user->login ?? 'ghost' ) . "**",
				'url' => $this->Payload->comment->html_url,
				'color' => $this->FormatAction(),
				'author' => $this->FormatAuthor(),
			];
		}

		throw new NotImplementedException( $this->EventType, $this->Payload->action );
	}

	/**
	 * Formats a pull request review event.
	 *
	 * @return mixed[]
	 */
	private function FormatPullRequestReviewEvent( ) : array
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'submitted'
		&&  $this->Payload->action !== 'dismissed' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$State = $this->Payload->review->state;

		if( $this->Payload->action === 'dismissed' )
		{
			$State = 'dismissed';
		}
		else if( $State === 'commented' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $State );
		}
		else if( $State === 'changes_requested' )
		{
			$State = 'requested changes in';
		}

		return [
			'title' => $State . ( $State === 'dismissed' ? ' a review on' : '' ) . " PR **#{$this->Payload->pull_request->number}**",
			'titleEnd' => ': ' . self::Escape( $this->Payload->pull_request->title ),
			// The body of a dismissed review is what the reviewer wrote, not why it was dismissed
			'description' => $State === 'dismissed' ? '' : self::FormatBody( $this->Payload->review->body ),
			'url' => $this->Payload->review->html_url,
			'color' => $State === 'dismissed' ? self::COLOR_BAD : $this->FormatAction( $State ),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a pull request review comment event.
	 *
	 * @return mixed[]
	 */
	private function FormatPullRequestReviewCommentEvent( ) : array
	{
		if( $this->Payload->action === 'edited'
		||  $this->Payload->action === 'deleted' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'created' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return [
			'title' => "commented on the code of PR **#{$this->Payload->pull_request->number}**",
			'titleEnd' => ': ' . self::Escape( $this->Payload->pull_request->title ),
			'description' => self::FormatBody( $this->Payload->comment->body ),
			'url' => $this->Payload->comment->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a discussion event.
	 *
	 * @return mixed[]
	 */
	private function FormatDiscussionEvent( ) : array
	{
		$Action = $this->Payload->action;

		if( $Action === 'edited'
		||  $Action === 'labeled'
		||  $Action === 'unlabeled'
		||  $Action === 'unanswered' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $Action );
		}

		if( $Action === 'category_changed' )
		{
			$Action = 'changed category';
		}

		if( $Action !== 'created'
		&&  $Action !== 'deleted'
		&&  $Action !== 'pinned'
		&&  $Action !== 'unpinned'
		&&  $Action !== 'locked'
		&&  $Action !== 'unlocked'
		&&  $Action !== 'transferred'
		&&  $Action !== 'answered'
		&&  $Action !== 'closed'
		&&  $Action !== 'reopened'
		&&  $Action !== 'changed category' )
		{
			throw new NotImplementedException( $this->EventType, $Action );
		}

		[ $Verb, $Suffix ] = self::ActionPhrase( $Action );

		$Embed = [
			'title' => "{$Verb} discussion **#{$this->Payload->discussion->number}**{$Suffix}",
			// The emoji of a category is a shortcode, which would keep Discord from making a link of the title
			'titleEnd' => ": {$this->Payload->discussion->category->emoji} " . self::Escape( $this->Payload->discussion->title ),
			'url' => $Action === 'answered' ? ( $this->Payload->answer->html_url ?? $this->Payload->discussion->html_url ) : $this->Payload->discussion->html_url,
			'color' => $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];

		if( $Action === 'created' )
		{
			$Embed[ 'description' ] = self::FormatBody( $this->Payload->discussion->body );
			$Embed[ 'footer' ] = self::LabelsFooter( $this->Payload->discussion->labels ?? null );
		}
		else if( $Action === 'answered' )
		{
			$Embed[ 'description' ] = self::FormatBody( $this->Payload->answer->body ?? null );
		}

		return $Embed;
	}

	/**
	 * Formats a discussion comment event.
	 *
	 * @return mixed[]
	 */
	private function FormatDiscussionCommentEvent( ) : array
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action === 'created' )
		{
			return [
				'title' => "commented on discussion **#{$this->Payload->discussion->number}**",
				'titleEnd' => ': ' . self::Escape( $this->Payload->discussion->title ),
				'description' => self::FormatBody( $this->Payload->comment->body ),
				'url' => $this->Payload->comment->html_url,
				'color' => $this->FormatAction(),
				'author' => $this->FormatAuthor(),
			];
		}

		if( $this->Payload->action === 'deleted' )
		{
			return [
				'title' => "deleted comment in discussion **#{$this->Payload->discussion->number}** from **" . ( $this->Payload->comment->user->login ?? 'ghost' ) . "**",
				'url' => $this->Payload->comment->html_url,
				'color' => $this->FormatAction(),
				'author' => $this->FormatAuthor(),
			];
		}

		throw new NotImplementedException( $this->EventType, $this->Payload->action );
	}

	/**
	 * Formats a dependabot alert event.
	 *
	 * @return mixed[]
	 */
	private function FormatDependabotAlertEvent( ) : array
	{
		if( $this->Payload->action === 'assignees_changed' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		$Action = match( $this->Payload->action )
		{
			'created' => 'created',
			'fixed' => 'fixed',
			'dismissed' => 'dismissed',
			'auto_dismissed' => 'auto-dismissed',
			'reopened', 'auto_reopened' => 'reopened',
			'reintroduced' => 'reintroduced',
			default => throw new NotImplementedException( $this->EventType, $this->Payload->action ),
		};

		$Advisory = $this->Payload->alert->security_advisory;
		$Vulnerability = $this->Payload->alert->security_vulnerability;

		$Embed = $this->AlertEmbed(
			"{$Action} Dependabot alert **#{$this->Payload->alert->number}**",
			" for **" . self::Escape( $Vulnerability->package->name ) . "**: " . self::Escape( $Advisory->summary ),
			$Action
		);

		if( $Action === 'created' )
		{
			$Embed[ 'description' ] = self::FormatBody( $Advisory->description ?? null );
			$Embed[ 'footer' ] = [ 'text' => $Advisory->severity . ' · ' . ( $Advisory->cve_id ?? $Advisory->ghsa_id ) ];
		}

		return $Embed;
	}

	/**
	 * Formats a code scanning alert event.
	 *
	 * @return mixed[]
	 */
	private function FormatCodeScanningAlertEvent( ) : array
	{
		if( $this->Payload->action === 'appeared_in_branch'
		||  $this->Payload->action === 'updated_assignment' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		$Action = match( $this->Payload->action )
		{
			'created' => 'created',
			'fixed' => 'fixed',
			'closed_by_user' => 'dismissed',
			'reopened', 'reopened_by_user' => 'reopened',
			default => throw new NotImplementedException( $this->EventType, $this->Payload->action ),
		};

		$Embed = $this->AlertEmbed(
			"{$Action} Code scanning alert **#{$this->Payload->alert->number}**",
			': ' . self::Escape( $this->Payload->alert->rule->description ),
			$Action
		);

		if( $Action === 'created' )
		{
			$Embed[ 'description' ] = self::FormatBody( $this->Payload->alert->most_recent_instance->message->text ?? null );
			$Embed[ 'footer' ] = [ 'text' => ( $this->Payload->alert->rule->severity ?? 'none' ) . ' · ' . $this->Payload->alert->rule->id ];
		}

		return $Embed;
	}

	/**
	 * Formats a secret scanning alert event.
	 *
	 * @return mixed[]
	 */
	private function FormatSecretScanningAlertEvent( ) : array
	{
		if( $this->Payload->action === 'assigned'
		||  $this->Payload->action === 'unassigned'
		||  $this->Payload->action === 'validated'
		||  $this->Payload->action === 'metadata_created'
		||  $this->Payload->action === 'metadata_removed' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		$Action = match( $this->Payload->action )
		{
			'created' => 'created',
			'resolved' => 'resolved',
			'reopened' => 'reopened',
			'publicly_leaked' => 'publicly leaked',
			default => throw new NotImplementedException( $this->EventType, $this->Payload->action ),
		};

		$SecretType = $this->Payload->alert->secret_type_display_name ?? $this->Payload->alert->secret_type ?? 'unknown';

		$Embed = $this->AlertEmbed(
			"{$Action} Secret scanning alert **#{$this->Payload->alert->number}**",
			': ' . self::Escape( $SecretType ),
			$Action
		);

		if( $Action === 'created' && isset( $this->Payload->alert->push_protection_bypassed_by ) )
		{
			$Embed[ 'description' ] = 'Push protection bypassed by **' . self::Escape( $this->Payload->alert->push_protection_bypassed_by->login ?? 'ghost' ) . '**';
		}
		else if( $Action === 'resolved' && ( $this->Payload->alert->resolution ?? '' ) !== '' )
		{
			$Embed[ 'description' ] = 'Resolved as ' . self::Escape( str_replace( '_', ' ', $this->Payload->alert->resolution ) );
		}

		return $Embed;
	}

	/**
	 * Formats a repository advisory event.
	 *
	 * @return mixed[]
	 */
	private function FormatRepositoryAdvisoryEvent( ) : array
	{
		$Advisory = $this->Payload->repository_advisory ?? null;

		if( $this->Payload->action === 'reported' )
		{
			// Reported advisories are private, so do not reveal what they are about
			return [
				'title' => 'privately reported a vulnerability',
				'titleEnd' => ': **' . self::Escape( $Advisory->ghsa_id ) . '**',
				'url' => $Advisory->html_url,
				'color' => self::COLOR_ATTENTION,
				'author' => $this->FormatAuthor(),
			];
		}

		if( $this->Payload->action !== 'published' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return [
			'title' => 'published a security advisory',
			'titleEnd' => ': ' . self::Escape( $Advisory->summary ),
			'description' => self::FormatBody( $Advisory->description ),
			'url' => $Advisory->html_url,
			'color' => self::COLOR_ATTENTION,
			'author' => $this->FormatAuthor(),
			// Not every advisory has a severity
			'footer' => [ 'text' => implode( ' · ', array_filter( [ $Advisory->severity ?? null, $Advisory->cve_id ?? $Advisory->ghsa_id ], static fn( ?string $Part ) : bool => $Part !== null ) ) ],
		];
	}

	/**
	 * Formats a member event.
	 *
	 * @return mixed[]
	 */
	private function FormatMemberEvent( ) : array
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'added' && $this->Payload->action !== 'removed' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return [
			'title' => "{$this->Payload->action} **" . ( $this->Payload->member->login ?? 'ghost' ) . "** as a collaborator",
			'url' => $this->Payload->repository->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a gollum event (wiki).
	 *
	 * @return mixed[]
	 */
	private function FormatGollumEvent( ) : array
	{
		$Messages = [];

		// Never more than a handful of pages
		foreach( array_slice( $this->Payload->pages, 0, self::MAX_WIKI_PAGES ) as $Page )
		{
			// A page title with parentheses ends up in the url, where they would end the markdown link
			$URL = str_replace( [ '(', ')' ], [ '%28', '%29' ], $Page->html_url );

			// Append compare url since github doesn't provide one
			if( $Page->action === 'edited' )
			{
				$URL .= '/_compare/' . $Page->sha;
			}

			// Nothing can be escaped in the text of a link, so the title of the page goes after it
			$Messages[] = "[{$Page->action}]({$URL}) " . self::Escape( $Page->title ) . ( ( $Page->summary ?? '' ) === '' ? '' : ( ': ' . self::ShortMessage( $Page->summary ) ) );
		}

		$Remaining = count( $this->Payload->pages ) - self::MAX_WIKI_PAGES;

		if( $Remaining > 0 )
		{
			$Messages[] = "and {$Remaining} more page" . ( $Remaining === 1 ? '' : 's' );
		}

		return [
			'title' => "updated wiki",
			'url' => $this->Payload->repository->html_url . '/wiki',
			'description' => implode( "\n", $Messages ),
			'color' => self::COLOR_NEUTRAL,
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a ping event.
	 *
	 * @return mixed[]
	 */
	private function FormatPingEvent( ) : array
	{
		return [
			'title' => "set up hook **{$this->Payload->hook_id}** — it works!",
			'description' => self::Escape( $this->Payload->zen ?? '' ),
			'color' => self::COLOR_NEUTRAL,
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Format a public event. Without a doubt: the best GitHub event.
	 *
	 * @return mixed[]
	 */
	private function FormatPublicEvent( ) : array
	{
		return [
			'title' => 'open sourced',
			'titleEnd' => " **" . self::Escape( $this->Payload->repository->name ) . "** — now available to everyone!",
			'url' => $this->Payload->repository->html_url,
			'color' => self::COLOR_DEFAULT,
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a repository event.
	 *
	 * @return mixed[]
	 */
	private function FormatRepositoryEvent( ) : array
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'deleted'
		&&  $this->Payload->action !== 'archived'
		&&  $this->Payload->action !== 'unarchived'
		&&  $this->Payload->action !== 'transferred'
		&&  $this->Payload->action !== 'renamed'
		&&  $this->Payload->action !== 'publicized'
		&&  $this->Payload->action !== 'privatized' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$TitleEnd = " **" . self::Escape( $this->Payload->repository->name ) . "**";

		if( $this->Payload->action === 'renamed' )
		{
			$TitleEnd .= self::FromSuffix( $this->Payload->changes->repository->name->from );
		}
		else if( $this->Payload->action === 'transferred' )
		{
			$Owner = $this->Payload->changes->owner->from->user ?? $this->Payload->changes->owner->from->organization ?? null;

			if( $Owner !== null )
			{
				$TitleEnd .= self::FromSuffix( $Owner->login );
			}
		}

		return [
			'title' => $this->Payload->action,
			'titleEnd' => $TitleEnd,
			'url' => $this->Payload->repository->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a project (classic) event.
	 *
	 * @return mixed[]
	 */
	private function FormatProjectEvent( ) : array
	{
		return $this->FormatProject(
			$this->Payload->project->number,
			$this->Payload->project->name,
			$this->Payload->project->body,
			$this->Payload->project->html_url
		);
	}

	/**
	 * Formats a project event.
	 *
	 * @return mixed[]
	 */
	private function FormatProjectV2Event( ) : array
	{
		$Project = $this->Payload->projects_v2;

		return $this->FormatProject(
			$Project->number,
			$Project->title,
			$Project->short_description,
			// Projects have no url of their own in the payload, they live under the organization that owns them
			sprintf( 'https://github.com/orgs/%s/projects/%d', $this->Payload->organization->login, $Project->number )
		);
	}

	/**
	 * Formats a project of either kind, which only differ in where their fields are.
	 *
	 * @return mixed[]
	 */
	private function FormatProject( int $Number, string $Title, ?string $Body, string $URL ) : array
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'closed'
		&&  $this->Payload->action !== 'reopened'
		&&  $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return [
			'title' => "{$this->Payload->action} project **#{$Number}**",
			'titleEnd' => ': ' . self::Escape( $Title ),
			'description' => $this->Payload->action === 'created' ? self::FormatBody( $Body ) : '',
			'url' => $URL,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a project status update event.
	 *
	 * @return mixed[]
	 */
	private function FormatProjectStatusUpdateEvent( ) : array
	{
		if( $this->Payload->action === 'edited'
		||  $this->Payload->action === 'deleted' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'created' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Update = $this->Payload->projects_v2_status_update;
		$Status = self::ProjectStatus( $Update->status ?? null );

		// The payload only has the node id of the project, so there is nothing to link but the list of them
		return [
			'title' => 'posted a project status update' . ( $Status === null ? '' : " ({$Status})" ),
			'description' => self::FormatBody( $Update->body ?? null ),
			'url' => 'https://github.com/orgs/' . $this->Payload->organization->login . '/projects',
			'color' => $Status === null ? self::COLOR_DEFAULT : $this->FormatAction( $Status ),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a branch protection configuration event.
	 *
	 * @return mixed[]
	 */
	private function FormatBranchProtectionConfigurationEvent( ) : array
	{
		if( $this->Payload->action !== 'enabled'
		&&  $this->Payload->action !== 'disabled' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return [
			'title' => "{$this->Payload->action} branch protection for all branches",
			'url' => $this->Payload->repository->html_url . '/settings/branches',
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a branch protection rule event.
	 *
	 * @return mixed[]
	 */
	private function FormatBranchProtectionRuleEvent( ) : array
	{
		// An edit changes a dozen settings at a time, which is too much to put in a title
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return [
			'title' => "{$this->Payload->action} branch protection rule",
			'titleEnd' => ' ' . self::EscapeCode( $this->Payload->rule->name ),
			'url' => $this->Payload->repository->html_url . '/settings/branches',
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a repository ruleset event.
	 *
	 * @return mixed[]
	 */
	private function FormatRepositoryRulesetEvent( ) : array
	{
		if( $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'edited'
		&&  $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Ruleset = $this->Payload->repository_ruleset;
		$Embed = [
			'title' => "{$this->Payload->action} ruleset",
			'titleEnd' => ": **" . self::Escape( $Ruleset->name ) . "** (" . self::Escape( $Ruleset->enforcement ) . ")",
			// Rulesets of an organization have no page of their own
			'url' => $Ruleset->_links->html->href ?? null,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];

		if( $Embed[ 'url' ] === null )
		{
			unset( $Embed[ 'url' ] );
		}

		return $Embed;
	}

	/**
	 * Formats a deploy key event.
	 *
	 * @return mixed[]
	 */
	private function FormatDeployKeyEvent( ) : array
	{
		if( $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		// A key that can write to the repository is worth telling apart from one that can not
		$Access = $this->Payload->key->read_only ? 'read-only' : 'read-write';

		return [
			'title' => "{$this->Payload->action} deploy key",
			'titleEnd' => ": **" . self::Escape( $this->Payload->key->title ) . "** ({$Access})",
			'url' => $this->Payload->repository->html_url . '/settings/keys',
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a meta event, which says that this very webhook was deleted.
	 *
	 * @return mixed[]
	 */
	private function FormatMetaEvent( ) : array
	{
		if( $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return [
			'title' => "deleted hook {$this->Payload->hook_id}",
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats an organization event.
	 *
	 * @return mixed[]
	 */
	private function FormatOrganizationEvent( ) : array
	{
		$Action = match( $this->Payload->action )
		{
			'deleted' => 'deleted',
			'renamed' => 'renamed',
			'member_added' => 'added',
			'member_removed' => 'removed',
			'member_invited' => 'invited',
			default => throw new NotImplementedException( $this->EventType, $this->Payload->action ),
		};

		if( $Action === 'deleted' || $Action === 'renamed' )
		{
			$From = $this->Payload->changes->login->from ?? null;

			$Title = "{$Action} the organization **" . self::Escape( $this->Payload->organization->login ) . "**";

			if( $From !== null )
			{
				$Title .= self::FromSuffix( $From );
			}
		}
		else
		{
			$Member = $this->OrganizationMember( );
			$Role = $this->OrganizationRole( );

			$Title = $Action . ' ' . ( $Member === null ? 'someone by email' : '**' . self::Escape( $Member ) . '**' );

			if( $Role !== null )
			{
				$Title .= ' (' . self::Escape( $Role ) . ')';
			}

			$Title .= ( $Action === 'removed' ? ' from' : ' to' ) . ' the organization';
		}

		return [
			'title' => $Title,
			'color' => $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats an organization block event.
	 *
	 * @return mixed[]
	 */
	private function FormatOrgBlockEvent( ) : array
	{
		if( $this->Payload->action !== 'blocked'
		&&  $this->Payload->action !== 'unblocked' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return [
			'title' => "{$this->Payload->action} user **" . self::Escape( $this->Payload->blocked_user->login ?? 'ghost' ) . "**",
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a sponsorship event, which only says that there is a new sponsor.
	 * What they pay is between them and the sponsored account.
	 *
	 * @return mixed[]
	 */
	private function FormatSponsorshipEvent( ) : array
	{
		if( $this->Payload->action === 'cancelled'
		||  $this->Payload->action === 'edited'
		||  $this->Payload->action === 'tier_changed'
		||  $this->Payload->action === 'pending_cancellation'
		||  $this->Payload->action === 'pending_tier_change' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'created' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Sponsor = $this->Payload->sponsorship->sponsor ?? null;
		$Sponsorable = $this->Payload->sponsorship->sponsorable ?? null;
		$Sponsored = $Sponsorable->login ?? 'ghost';
		$IsPublic = ( $this->Payload->sponsorship->privacy_level ?? null ) === 'public' && $Sponsor !== null;

		// The sender is the sponsor, who asked not to be named when the sponsorship is private
		$Author = $IsPublic ? $Sponsor : $Sponsorable;

		return [
			'title' => $IsPublic ? "is now sponsoring **{$Sponsored}**" : 'got a new private sponsor',
			'url' => 'https://github.com/sponsors/' . $Sponsored,
			'color' => self::COLOR_DEFAULT,
			'author' => [
				'name' => $Author->login ?? 'ghost',
				'url' => $Author->html_url ?? 'https://github.com/ghost',
				'icon_url' => $Author->avatar_url ?? 'https://github.com/ghost.png',
			],
		];
	}

	/**
	 * Formats a workflow run event. Only a run that broke the default branch is worth telling,
	 * the rest would be noise.
	 *
	 * @return mixed[]
	 */
	private function FormatWorkflowRunEvent( ) : array
	{
		if( $this->Payload->action === 'requested'
		||  $this->Payload->action === 'in_progress' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'completed' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Run = $this->Payload->workflow_run;

		$Outcome = match( $Run->conclusion ?? null )
		{
			'failure' => 'failed',
			'timed_out' => 'timed out',
			'startup_failure' => 'failed to start',
			default => throw new IgnoredEventException( $this->EventType . ' - ' . ( $Run->conclusion ?? 'null' ) ),
		};

		if( ( $Run->head_branch ?? null ) !== $this->Payload->repository->default_branch )
		{
			throw new IgnoredEventException( $this->EventType . ' - not the default branch' );
		}

		$Name = $Run->name ?? '';

		if( $Name === '' )
		{
			$Name = $this->Payload->workflow->name ?? 'unknown';
		}

		return [
			'title' => 'broke',
			'titleEnd' => " " . self::EscapeCode( $Run->head_branch ) . " — workflow **" . self::Escape( $Name ) . "** {$Outcome}",
			'description' => self::ShortMessage( $Run->head_commit->message ?? '' ),
			'url' => $Run->html_url,
			'color' => $this->FormatAction( $Outcome ),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a membership event.
	 *
	 * @return mixed[]
	 */
	private function FormatMembershipEvent( ) : array
	{
		if( $this->Payload->action !== 'added'
		&&  $this->Payload->action !== 'removed' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Where = $this->Payload->action === 'added' ? 'to' : 'from';

		return [
			'title' => "{$this->Payload->action} **" . ( $this->Payload->member->login ?? 'ghost' ) . "** {$Where} team",
			'titleEnd' => " **" . self::Escape( $this->Payload->team->name ) . "**",
			'url' => $this->Payload->team->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a team event.
	 *
	 * @return mixed[]
	 */
	private function FormatTeamEvent( ) : array
	{
		[ $Action, $Where ] = match( $this->Payload->action )
		{
			'created' => [ 'created', '' ],
			'deleted' => [ 'deleted', '' ],
			'edited' => [ 'edited', '' ],
			'added_to_repository' => [ 'added', ' to this repository' ],
			'removed_from_repository' => [ 'removed', ' from this repository' ],
			default => throw new NotImplementedException( $this->EventType, $this->Payload->action ),
		};

		$From = null;
		$Title = null;
		$TitleEnd = null;

		// An edit is worth telling when it renames a team or changes who can see it
		if( $Action === 'edited' )
		{
			$From = $this->Payload->changes->name->from ?? null;

			if( $From !== null )
			{
				$Action = 'renamed';
			}
			else if( isset( $this->Payload->changes->privacy ) )
			{
				$Title = 'changed the privacy of team';
				$TitleEnd = " **" . self::Escape( $this->Payload->team->name ) . "** to **" . self::Escape( $this->Payload->team->privacy ?? 'unknown' ) . "**";
			}
			else
			{
				throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
			}
		}

		$Title ??= "{$Action} team";
		$TitleEnd ??= " **" . self::Escape( $this->Payload->team->name ) . "**{$Where}";

		if( $From !== null )
		{
			$TitleEnd .= self::FromSuffix( $From );
		}

		return [
			'title' => $Title,
			'titleEnd' => $TitleEnd,
			'url' => $this->Payload->team->html_url,
			'color' => $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * The user an organization member event is about. An invitation by email has no account yet,
	 * and the address is not something to announce.
	 */
	private function OrganizationMember( ) : ?string
	{
		if( $this->Payload->action === 'member_invited' )
		{
			return $this->Payload->user->login ?? $this->Payload->invitation->login ?? null;
		}

		return $this->Payload->membership->user->login ?? 'ghost';
	}

	/**
	 * The role of the member an organization event is about. An invitation calls a plain member
	 * a direct member, and reinstating someone gives back the role they had, which is not named.
	 */
	private function OrganizationRole( ) : ?string
	{
		$Role = $this->Payload->membership->role ?? $this->Payload->invitation->role ?? null;

		if( !is_string( $Role ) || $Role === 'reinstate' )
		{
			return null;
		}

		return $Role === 'direct_member' ? 'member' : str_replace( '_', ' ', $Role );
	}

	/**
	 * GitHub sends the status of a project as an enum such as `OFF_TRACK`, and it can be unset.
	 */
	private static function ProjectStatus( mixed $Status ) : ?string
	{
		return is_string( $Status ) ? strtolower( str_replace( '_', ' ', $Status ) ) : null;
	}
}
