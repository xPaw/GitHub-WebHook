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

	private const int COLOR_DEFAULT = 5025616;
	private const int COLOR_ATTENTION = 16750592;
	private const int COLOR_BAD = 16007990;
	private const int COLOR_CLOSED = 8540383;
	private const int COLOR_NOT_PLANNED = 7239297;

	private const int MAX_TITLE_LENGTH = 256;
	private const int MAX_DESCRIPTION_LENGTH = 4096;
	private const int MAX_FIELD_NAME_LENGTH = 256;
	private const int MAX_FIELD_VALUE_LENGTH = 1024;
	private const int MAX_FOOTER_LENGTH = 2048;

	/**
	 * Parses GitHub's webhook payload and returns a formatted message.
	 *
	 * @return mixed[]
	 */
	public function GetEmbed( ) : array
	{
		// New branches and tags are formatted from the push event, which has the commits
		if( $this->EventType === 'create'
		||  $this->EventType === 'fork'
		||  $this->EventType === 'watch'
		||  $this->EventType === 'star'
		||  $this->EventType === 'status' )
		{
			throw new IgnoredEventException( $this->EventType );
		}

		$Embed = null;

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
		}

		if( empty( $Embed ) )
		{
			throw new NotImplementedException( $this->EventType );
		}

		// Discord rejects the whole message when an embed is over its limits
		if( is_string( $Embed[ 'title' ] ) )
		{
			$Embed[ 'title' ] = self::LimitLength( $Embed[ 'title' ], self::MAX_TITLE_LENGTH );
		}

		if( !is_string( $Embed[ 'description' ] ?? null ) || $Embed[ 'description' ] === '' )
		{
			unset( $Embed[ 'description' ] );
		}
		else
		{
			$Embed[ 'description' ] = self::LimitLength( $Embed[ 'description' ], self::MAX_DESCRIPTION_LENGTH );
		}

		if( is_array( $Embed[ 'footer' ] ?? null ) && is_string( $Embed[ 'footer' ][ 'text' ] ?? null ) )
		{
			$Embed[ 'footer' ][ 'text' ] = self::LimitLength( $Embed[ 'footer' ][ 'text' ], self::MAX_FOOTER_LENGTH );
		}

		if( is_array( $Embed[ 'fields' ] ?? null ) )
		{
			foreach( $Embed[ 'fields' ] as &$Field )
			{
				if( is_array( $Field ) && is_string( $Field[ 'name' ] ?? null ) && is_string( $Field[ 'value' ] ?? null ) )
				{
					$Field[ 'name' ] = self::LimitLength( $Field[ 'name' ], self::MAX_FIELD_NAME_LENGTH );
					$Field[ 'value' ] = self::LimitLength( $Field[ 'value' ], self::MAX_FIELD_VALUE_LENGTH );
				}
			}

			unset( $Field );
		}

		return [
			'embeds' => [ $Embed ],
		];
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

	/** Whether an alert is open after this action, and so needs attention. */
	private static function IsOpenAlert( string $Action ) : bool
	{
		return $Action === 'created'
			|| $Action === 'reopened'
			|| $Action === 'reintroduced'
			|| $Action === 'publicly leaked';
	}

	private static function EscapeCode( string $Message ) : string
	{
		return '`' . str_replace( '`',  '``', $Message ) . '`';
	}

	private static function Escape( string $Message ) : string
	{
		return str_replace( [
			'\\',   '*',  '|',  '`',  '[',  ']',  '(',  ')',  '<',  '>',  '_',  '~',
		], [
			'\\\\', '\*', '\|', '\`', '\[', '\]', '\(', '\)', '\<', '\>', '\_', '\~',
		], $Message );
	}

	/** @return array{name: string, url: string, icon_url: string} */
	private function FormatAuthor() : array
	{
		return [
			'name' => $this->Payload->sender->login,
			'url' => $this->Payload->sender->html_url,
			'icon_url' => $this->Payload->sender->avatar_url,
		];
	}

	private function FormatAction( ?string $Action = null ) : int
	{
		if( $Action === null )
		{
			$Action = $this->Payload->action;
		}

		switch( $Action )
		{
			// Something needs attention again
			case 'reintroduced':
			case 'reopened'   : return self::COLOR_ATTENTION;

			case 'locked'     :
			case 'deleted'    :
			case 'removed'    :
			case 'dismissed'  :
			case 'auto-dismissed':
			case 'publicly leaked':
			case 'unpublished':
			case 'force-pushed':
			case 'requested changes in':
			case 'closed without merging': return self::COLOR_BAD;

			case 'closed as not planned': return self::COLOR_NOT_PLANNED;

			case 'closed'     :
			case 'merged'     : return self::COLOR_CLOSED;

			default           : return self::COLOR_DEFAULT;
		}
	}

	private static function ShortDescription( ?string $Message, int $Limit = 250 ) : string
	{
		$Message ??= '';
		$Message = preg_replace( self::HTML_COMMENT, '', $Message ) ?? $Message;
		$Message = preg_replace( self::HTML_TAG, '', $Message ) ?? $Message;
		$Message = str_replace( [ "\r", "\n\n" ], [ "", "\n" ], $Message );
		$Message = self::Trim( $Message );

		// Limit amount of new lines
		$Lines = explode( "\n", $Message );

		if( count( $Lines ) > 11 )
		{
			$Message = implode( "\n", array_slice( $Lines, 0, 11 ) ) . ' ' . implode( ' ', array_slice( $Lines, 11 ) );
		}

		if( mb_strlen( $Message ) > $Limit )
		{
			$Message = mb_substr( $Message, 0, $Limit );
			$Message .= '…';
		}

		return $Message;
	}

	private static function ShortMessage( string $Message, int $Limit = 100 ) : string
	{
		$Message = self::Trim( $Message );
		$NewMessage = explode( "\n", $Message, 2 );
		$NewMessage = $NewMessage[ 0 ];

		if( mb_strlen( $NewMessage ) > $Limit )
		{
			$NewMessage = mb_substr( $NewMessage, 0, $Limit );
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

		$Embed = [
			'title' => '',
			'url' => $this->Payload->compare,
			'color' => self::COLOR_DEFAULT,
			'author' => $this->FormatAuthor(),
		];

		if( isset( $this->Payload->created ) && $this->Payload->created )
		{
			if( substr( $this->Payload->ref, 0, 10 ) === 'refs/tags/' )
			{
				$Embed[ 'title' ] = "tagged " . self::EscapeCode( $this->RefName ) . " at " . self::EscapeCode( $this->BaseRefName ?? $this->AfterSHA() );
			}
			else
			{
				$Embed[ 'title' ] = "created " . self::EscapeCode( $this->RefName );

				if( $this->BaseRefName !== null )
				{
					$Embed[ 'title' ] .= " from " . self::EscapeCode( $this->BaseRefName );
				}
				else if( $Num > 0 )
				{
					$Embed[ 'title' ] .= " at " . self::EscapeCode( $this->AfterSHA() );
				}

				if( $Num > 0 )
				{
					$Embed[ 'title' ] .= sprintf( ' (+%d new commit%s)',
						$Num,
						$Num === 1 ? '' : 's'
					);
				}
			}
		}
		else if( isset( $this->Payload->deleted ) && $this->Payload->deleted )
		{
			throw new NotImplementedException( $this->EventType, 'deleted (use DeleteEvent if needed)' );
		}
		else if( isset( $this->Payload->forced ) && $this->Payload->forced )
		{
			$Embed[ 'title' ] = "force-pushed " . self::EscapeCode( $this->RefName ) . " from " . self::EscapeCode( $this->BeforeSHA() ) . " to " . self::EscapeCode( $this->AfterSHA() );
			$Embed[ 'color' ] = self::COLOR_BAD;
		}
		else if( $Num === 0 && count( $this->Payload->commits ) > 0 )
		{
			if( $this->BaseRefName !== null )
			{
				$Embed[ 'title' ] = "merged " . self::EscapeCode( $this->BaseRefName ) . " into " . self::EscapeCode( $this->RefName );
				$Embed[ 'color' ] = self::COLOR_CLOSED;
			}
			else
			{
				$Embed[ 'title' ] = "fast-forwarded " . self::EscapeCode( $this->RefName ) . " from " . self::EscapeCode( $this->BeforeSHA() ) . " to " . self::EscapeCode( $this->AfterSHA() );
			}
		}
		else
		{
			$Embed[ 'title' ] = sprintf( 'pushed %d new commit%s to %s',
				$Num,
				$Num === 1 ? '' : 's',
				self::EscapeCode( $this->RefName )
			);
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
			$CommitsLimit = 5;

			while( --$Num >= 0 && --$CommitsLimit >= 0 )
			{
				$DistinctCommit = $DistinctCommits[ $Num ];

				$Commit = "[" . self::EscapeCode( substr( $DistinctCommit->id, 0, 6 ) ) . "]({$DistinctCommit->url}) ";
				$Commit .= self::ShortMessage( $DistinctCommit->message );

				if( isset( $DistinctCommit->author->username ) )
				{
					if( $DistinctCommit->author->username !== $this->Payload->sender->login )
					{
						$Commit .= " - " . self::Escape( $DistinctCommit->author->username );
					}
				}
				else
				{
					$Commit .= " - *" . self::Escape( $DistinctCommit->author->name ) . "*";
				}

				$CommitMessages[] = $Commit;
			}

			$Embed[ 'description' ] = implode( "\n", $CommitMessages );
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
			'title' => "deleted {$this->Payload->ref_type} " . self::EscapeCode( $this->Payload->ref ),
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
		||  $Action === 'untyped' )
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
			'title' => "{$Verb} issue **#{$this->Payload->issue->number}**{$Suffix}: " . self::Escape( $this->Payload->issue->title ),
			'url' => $this->Payload->issue->html_url,
			'color' => $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];

		if( $Action === 'opened' )
		{
			$Embed[ 'description' ] = self::ShortDescription( $this->Payload->issue->body );

			if( !empty( $this->Payload->issue->labels ) )
			{
				$Labels = [];

				foreach( $this->Payload->issue->labels as $Label )
				{
					$Labels[] = $Label->name;
				}

				$Embed[ 'footer' ][ 'text' ] = implode( ' · ', $Labels );
			}
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
		||  $Action === 'auto_merge_disabled' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $Action );
		}

		if( $Action !== 'opened'
		&&  $Action !== 'reopened'
		&&  $Action !== 'deleted'
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
			'title' => "{$Verb} {$Draft}PR **#{$this->Payload->pull_request->number}**{$Suffix}: " . self::Escape( $this->Payload->pull_request->title ),
			'url' => $this->Payload->pull_request->html_url,
			'color' => $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];

		if( $Action === 'opened' )
		{
			$Embed[ 'description' ] = self::ShortDescription( $this->Payload->pull_request->body );
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
			'title' => "{$Action} milestone **#{$this->Payload->milestone->number}**: " . self::Escape( $this->Payload->milestone->title ),
			'description' => $Action === 'created' ? self::ShortDescription( $this->Payload->milestone->description ) : '',
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
			'title' => "{$this->Payload->action} " . self::Escape( strtolower( $Package->package_type ) ) . " package: **" . self::Escape( $Package->name ) . "**" . ( $Version === '' ? '' : ' ' . self::Escape( $Version ) ),
			// Container packages have an empty object as their body
			'description' => $this->Payload->action === 'published' && is_string( $Body ) ? self::ShortDescription( $Body ) : '',
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
			'title' => "{$this->Payload->action} a " . ( $this->Payload->release->draft ? 'draft ' : '' ) . ( $this->Payload->release->prerelease ? 'pre-' : '' ) . "release: " . self::Escape( $Name ),
			// Release notes are only worth showing when the release appears
			'description' => $this->Payload->action === 'published' ? self::ShortDescription( $this->Payload->release->body ) : '',
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
			'description' => self::ShortDescription( $this->Payload->comment->body ),
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
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		$IsPullRequest = isset( $this->Payload->issue->pull_request );

		if( $this->Payload->action === 'created' )
		{
			return [
				'title' => "commented on " . ( $IsPullRequest ? 'PR' : 'issue' ) . " **#{$this->Payload->issue->number}**: " . self::Escape( $this->Payload->issue->title ),
				'description' => self::ShortDescription( $this->Payload->comment->body ),
				'url' => $this->Payload->comment->html_url,
				'color' => $this->FormatAction(),
				'author' => $this->FormatAuthor(),
			];
		}

		if( $this->Payload->action === 'deleted' )
		{
			return [
				'title' => "deleted comment in " . ( $IsPullRequest ? 'PR' : 'issue' ) . " **#{$this->Payload->issue->number}** from **" . self::Escape( $this->Payload->comment->user->login ?? 'ghost' ) . "**",
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
			'title' => $State . ( $State === 'dismissed' ? ' a review on' : '' ) . " PR **#{$this->Payload->pull_request->number}**: " . self::Escape( $this->Payload->pull_request->title ),
			// The body of a dismissed review is what the reviewer wrote, not why it was dismissed
			'description' => $State === 'dismissed' ? '' : self::ShortDescription( $this->Payload->review->body ),
			'url' => $this->Payload->review->html_url,
			'color' => $this->FormatAction( $State ),
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
			'title' => "reviewed PR **#{$this->Payload->pull_request->number}**: " . self::Escape( $this->Payload->pull_request->title ),
			'description' => self::ShortDescription( $this->Payload->comment->body ),
			'url' => $this->Payload->comment->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a pull request review comment event.
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
			'title' => "{$Verb} discussion **#{$this->Payload->discussion->number}**{$Suffix}: {$this->Payload->discussion->category->emoji} " . self::Escape( $this->Payload->discussion->title ),
			'url' => $Action === 'answered' ? ( $this->Payload->answer->html_url ?? $this->Payload->discussion->html_url ) : $this->Payload->discussion->html_url,
			'color' => $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];

		if( $Action === 'created' )
		{
			$Embed[ 'description' ] = self::ShortDescription( $this->Payload->discussion->body );
		}

		return $Embed;
	}

	/**
	 * Formats a pull request review comment event.
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
				'title' => "commented on discussion **#{$this->Payload->discussion->number}**: " . self::Escape( $this->Payload->discussion->title ),
				'description' => self::ShortDescription( $this->Payload->comment->body ),
				'url' => $this->Payload->comment->html_url,
				'color' => $this->FormatAction(),
				'author' => $this->FormatAuthor(),
			];
		}

		if( $this->Payload->action === 'deleted' )
		{
			return [
				'title' => "deleted comment in discussion **#{$this->Payload->discussion->number}** from **" . self::Escape( $this->Payload->comment->user->login ?? 'ghost' ) . "**",
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

		$Embed =
		[
			'title' => "Dependabot alert **#{$this->Payload->alert->number}** {$Action} for **" . self::Escape( $Vulnerability->package->name ) . "**: " . self::Escape( $Advisory->summary ),
			'url' => $this->Payload->alert->html_url,
			'color' => $Action === 'created' ? self::COLOR_ATTENTION : $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];

		if( self::IsOpenAlert( $Action ) )
		{
			$Embed[ 'title' ] = '⚠ ' . $Embed[ 'title' ];
		}

		if( $Action === 'created' )
		{
			$Embed[ 'description' ] = self::ShortDescription( $Advisory->description ?? null );
			$Embed[ 'fields' ] =
			[
				[
					'name' => 'Severity',
					'value' => self::Escape( $Advisory->severity )
				],
				[
					'name' => 'Affected range',
					'value' => self::Escape( $Vulnerability->vulnerable_version_range )
				],
			];

			if( isset( $Vulnerability->first_patched_version ) )
			{
				$Embed[ 'fields' ][] =
				[
					'name' => 'Fixed in',
					'value' => self::Escape( $Vulnerability->first_patched_version->identifier )
				];
			}

			$Embed[ 'fields' ][] =
			[
				'name' => 'Identifier',
				'value' => self::Escape( $Advisory->cve_id ?? $Advisory->ghsa_id )
			];
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
		if( $this->Payload->action === 'appeared_in_branch' )
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

		$Embed =
		[
			'title' => "Code scanning alert **#{$this->Payload->alert->number}** {$Action}: " . self::Escape( $this->Payload->alert->rule->description ),
			'url' => $this->Payload->alert->html_url,
			'color' => $Action === 'created' ? self::COLOR_ATTENTION : $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];

		if( self::IsOpenAlert( $Action ) )
		{
			$Embed[ 'title' ] = '⚠ ' . $Embed[ 'title' ];
		}

		if( $Action === 'created' )
		{
			$Embed[ 'description' ] = self::ShortDescription( $this->Payload->alert->most_recent_instance->message->text ?? null );
			$Embed[ 'fields' ] =
			[
				[
					'name' => 'Severity',
					'value' => self::Escape( $this->Payload->alert->rule->severity ?? 'none' )
				],
				[
					'name' => 'Tool',
					'value' => self::Escape( $this->Payload->alert->tool->name ?? 'unknown' )
				],
				[
					'name' => 'Identifier',
					'value' => self::Escape( $this->Payload->alert->rule->id )
				],
			];
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
		||  $this->Payload->action === 'validated' )
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

		$Embed =
		[
			'title' => "Secret scanning alert **#{$this->Payload->alert->number}** {$Action}: " . self::Escape( $SecretType ),
			'url' => $this->Payload->alert->html_url,
			'color' => $Action === 'created' ? self::COLOR_ATTENTION : $this->FormatAction( $Action ),
			'author' => $this->FormatAuthor(),
		];

		if( self::IsOpenAlert( $Action ) )
		{
			$Embed[ 'title' ] = '⚠ ' . $Embed[ 'title' ];
		}

		if( $Action === 'created' && isset( $this->Payload->alert->push_protection_bypassed_by ) )
		{
			$Embed[ 'description' ] = 'Push protection bypassed by **' . self::Escape( $this->Payload->alert->push_protection_bypassed_by->login ) . '**';
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
				'title' => "⚠ privately reported a vulnerability: **" . self::Escape( $Advisory->ghsa_id ) . "**",
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
			'title' => "⚠ published a security advisory: " . self::Escape( $Advisory->summary ),
			'description' => self::ShortDescription( $Advisory->description ),
			'url' => $Advisory->html_url,
			'color' => self::COLOR_ATTENTION,
			'author' => $this->FormatAuthor(),
			'fields' =>
			[
				[
					'name' => 'Severity',
					'value' => self::Escape( $Advisory->severity ?? 'unknown' )
				],
				[
					'name' => 'Identifier',
					'value' => self::Escape( $Advisory->cve_id ?? $Advisory->ghsa_id )
				],
			],
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
			'title' => "{$this->Payload->action} **" . self::Escape( $this->Payload->member->login ?? 'ghost' ) . "** as a collaborator",
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

		// Never more than five pages, the same as commits in a push
		foreach( array_slice( $this->Payload->pages, 0, self::MAX_WIKI_PAGES ) as $Page )
		{
			// A page title with parentheses ends up in the url, where they would end the markdown link
			$URL = str_replace( [ '(', ')' ], [ '%28', '%29' ], $Page->html_url );

			// Append compare url since github doesn't provide one
			if( $Page->action === 'edited' )
			{
				$URL .= '/_compare/' . $Page->sha;
			}

			$Messages[] = "[{$Page->action} " . self::Escape( $Page->title ) . "]({$URL})" . ( ( $Page->summary ?? '' ) === '' ? '' : ( ': ' . self::ShortMessage( $Page->summary ) ) );
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
			'color' => self::COLOR_DEFAULT,
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
			'title' => "Hook {$this->Payload->hook_id} worked!",
			'description' => self::Escape( $this->Payload->zen ?? '' ),
			'color' => self::COLOR_DEFAULT,
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
			'title' => "**" . self::Escape( $this->Payload->repository->name ) . "** is now open source and available to everyone!",
			'url' => $this->Payload->repository->html_url,
			'color' => self::COLOR_DEFAULT,
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Triggered when a repository is created.
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

		$Title = "{$this->Payload->action} **" . self::Escape( $this->Payload->repository->name ) . "**";

		if( $this->Payload->action === 'renamed' )
		{
			$Title .= " (from *" . self::Escape( $this->Payload->changes->repository->name->from ) . "*)";
		}
		else if( $this->Payload->action === 'transferred' )
		{
			$Owner = $this->Payload->changes->owner->from->user ?? $this->Payload->changes->owner->from->organization ?? null;

			if( $Owner !== null )
			{
				$Title .= " (from **" . self::Escape( $Owner->login ) . "**)";
			}
		}

		return [
			'title' => $Title,
			'url' => $this->Payload->repository->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}
}
