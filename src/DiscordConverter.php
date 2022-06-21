<?php
declare(strict_types=1);

class DiscordConverter extends BaseConverter
{
	/**
	 * Parses GitHub's webhook payload and returns a formatted message
	 *
	 * @return array
	 */
	public function GetEmbed( ) : array
	{
		if( $this->EventType === 'fork'
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
			case 'package'       : $Embed = $this->FormatPackageEvent( ); break;
			case 'project'       : $Embed = $this->FormatProjectEvent( ); break;
			case 'release'       : $Embed = $this->FormatReleaseEvent( ); break;
			case 'milestone'     : $Embed = $this->FormatMilestoneEvent( ); break;
			case 'repository'    : $Embed = $this->FormatRepositoryEvent( ); break;
			case 'pull_request'  : $Embed = $this->FormatPullRequestEvent( ); break;
			case 'issue_comment' : $Embed = $this->FormatIssueCommentEvent( ); break;
			case 'commit_comment': $Embed = $this->FormatCommitCommentEvent( ); break;
			case 'pull_request_review': $Embed = $this->FormatPullRequestReviewEvent( ); break;
			case 'pull_request_review_comment': $Embed = $this->FormatPullRequestReviewCommentEvent( ); break;
			case 'repository_vulnerability_alert': $Embed = $this->FormatRepositoryVulnerabilityAlertEvent( ); break;
		}

		if( empty( $Embed ) )
		{
			throw new NotImplementedException( $this->EventType );
		}

		if( empty( $Embed[ 'description' ] ) )
		{
			unset( $Embed[ 'description' ] );
		}

		return [
			'embeds' => [ $Embed ],
		];
	}

	private static function EscapeCode( string $Message ) : string
	{
		return '`' . str_replace( '`',  '``', $Message ) . '`';
	}

	private static function Escape( string $Message ) : string
	{
		return str_replace( [
			'\\',   '*',  '|',  '`',  '[',  ']',  '(',  ')',  '<',  '>',  '_',
		], [
			'\\\\', '\*', '\|', '\`', '\[', '\]', '\(', '\)', '\<', '\>', '\_',
		], $Message );
	}

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
			case 'enabled auto-merge':
			case 'created'    :
			case 'resolved'   :
			case 'reopened'   : return 16750592;

			case 'locked'     :
			case 'deleted'    :
			case 'dismissed'  :
			case 'unpublished':
			case 'force-pushed':
			case 'requested changes in':
			case 'closed without merging': return 16007990;

			case 'closed as not planned':
			case 'closed'     : return 8540383;
			case 'merged'     : return 7291585;

			default           : return 5025616;
		}
	}

	private function ShortDescription( ?string $Message, int $Limit = 250 ) : string
	{
		$Message ??= '';
		$Message = strip_tags( $Message );
		$Message = str_replace( [ "\r", "\n\n" ], [ "", "\n" ], $Message );

		// Limit amount of new lines
		$Length = strlen( $Message );
		$NewLines = 0;

		for( $i = 0; $i < $Length; $i++ )
		{
			if( $Message[ $i ] === "\n" )
			{
				if( ++$NewLines > 10 )
				{
					$Message[ $i ] = ' ';
				}
			}
		}

		if( strlen( $Message ) > $Limit )
		{
			$Message = substr( $Message, 0, $Limit );
			$Message .= '…';
		}

		return $Message;
	}

	private function ShortMessage( string $Message, int $Limit = 100 ) : string
	{
		$Message = trim( $Message );
		$NewMessage = explode( "\n", $Message, 2 );
		$NewMessage = $NewMessage[ 0 ];

		if( strlen( $NewMessage ) > $Limit )
		{
			$NewMessage = substr( $Message, 0, $Limit );
		}

		if( $NewMessage !== $Message )
		{
			// Tidy ellipsis
			if( substr( $NewMessage, -3 ) === '...' )
			{
				$NewMessage = substr( $NewMessage, 0, -3 ) . '…';
			}
			else if( substr( $NewMessage, -1 ) !== '…' )
			{
				$NewMessage .= '…';
			}
		}

		return $this->Escape( $NewMessage );
	}

	/**
	 * Formats a push event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#push
	 */
	private function FormatPushEvent( ) : array
	{
		$DistinctCommits = $this->GetDistinctCommits( );
		$Num = count( $DistinctCommits );

		$Embed = [
			'title' => '',
			'url' => $this->Payload->repository->html_url,
			'author' => $this->FormatAuthor(),
		];

		if( isset( $this->Payload->created ) && $this->Payload->created )
		{
			if( substr( $this->Payload->ref, 0, 10 ) === 'refs/tags/' )
			{
				$Embed[ 'title' ] = "tagged {$this->EscapeCode( $this->Payload->ref_name )} at " . $this->EscapeCode( $this->Payload->base_ref_name ?? $this->AfterSHA() );
				$Embed[ 'color' ] = $this->FormatAction( 'tagged' );
			}
			else
			{
				$Embed[ 'title' ] = "created {$this->EscapeCode( $this->Payload->ref_name )}";

				if( isset( $this->Payload->base_ref ) )
				{
					$Embed[ 'title' ] .= " from {$this->EscapeCode( $this->Payload->base_ref_name )}";
				}
				else if( $Num > 0 )
				{
					$Embed[ 'title' ] .= " at {$this->EscapeCode( $this->AfterSHA() )}";
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
			$this->Payload->action = 'force-pushed'; // Don't tell anyone!

			$Embed[ 'title' ] = "{$this->Payload->action} {$this->EscapeCode( $this->Payload->ref_name )} from {$this->Escape( $this->BeforeSHA() )} to {$this->Escape( $this->AfterSHA() )}";
			$Embed[ 'color' ] = $this->FormatAction();
		}
		else if( $Num === 0 && count( $this->Payload->commits ) > 0 )
		{
			if( isset( $this->Payload->base_ref ) )
			{
				$Embed[ 'title' ] = "merged {$this->EscapeCode( $this->Payload->base_ref_name )} into {$this->EscapeCode( $this->Payload->ref_name )}";
				$Embed[ 'color' ] = $this->FormatAction( 'merged' );
			}
			else
			{
				$Embed[ 'title' ] = "fast-forwarded {$this->EscapeCode( $this->Payload->ref_name )} from {$this->Escape( $this->BeforeSHA() )} to {$this->Escape( $this->AfterSHA() )}";
				$Embed[ 'color' ] = $this->FormatAction( 'fast-forwarded' );
			}
		}
		else
		{
			$Embed[ 'title' ] = sprintf( 'pushed %d new commit%s to %s',
				$Num,
				$Num === 1 ? '' : 's',
				$this->EscapeCode( $this->Payload->ref_name )
			);
		}

		if( $this->Payload->forced )
		{
			// GitHub supports displaying proper diffs for force pushes
			// but it only appears to work if the diff url has full hashes
			// so we construct the url ourselves, instead of using the url in the payload
			// Note: this uses ".." instead of "..." to force github to actually display changes between the commits
			// and not the entire diff of the force push
			$Embed[ 'url' ] = "{$this->Payload->repository->url}/compare/{$this->Payload->before}..{$this->Payload->after}";
		}
		else
		{
			$Embed[ 'url' ] = $this->Payload->compare;
		}

		if( $Num > 0 )
		{
			$CommitMessages = [];
			$CommitsLimit = 5;

			while( --$Num >= 0 && --$CommitsLimit >= 0 )
			{
				$DistinctCommit = $DistinctCommits[ $Num ];

				$Commit = "[{$this->Escapecode( substr( $DistinctCommit->id, 0, 6 ) )}]({$DistinctCommit->url}) ";
				$Commit .= $this->ShortMessage( $DistinctCommit->message );

				if( isset( $DistinctCommit->author->username ) )
				{
					if( $DistinctCommit->author->username !== $this->Payload->sender->login )
					{
						$Commit .= " - {$this->Escape( $DistinctCommit->author->username )}";
					}
				}
				else
				{
					$Commit .= " - *{$this->Escape( $DistinctCommit->author->name )}*";
				}

				$CommitMessages[] = $Commit;
			}

			$Embed[ 'description' ] = implode( "\n", $CommitMessages );
		}

		return $Embed;
	}

	/**
	 * Formats a deletion event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#delete
	 */
	private function FormatDeleteEvent( ) : array
	{
		if( $this->Payload->ref_type !== 'tag'
		&&  $this->Payload->ref_type !== 'branch' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->ref_type );
		}

		return [
			'title' => "deleted {$this->Payload->ref_type} {$this->EscapeCode( $this->Payload->ref )}",
			'url' => $this->Payload->repository->html_url,
			'color' => $this->FormatAction( 'deleted' ),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats an issue event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#issues
	 */
	private function FormatIssuesEvent( ) : array
	{
		if( $this->Payload->action === 'edited'
		||  $this->Payload->action === 'unpinned'
		||  $this->Payload->action === 'milestoned'
		||  $this->Payload->action === 'demilestoned'
		||  $this->Payload->action === 'labeled'
		||  $this->Payload->action === 'unlabeled'
		||  $this->Payload->action === 'assigned'
		||  $this->Payload->action === 'unassigned' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'opened'
		&&  $this->Payload->action !== 'closed'
		&&  $this->Payload->action !== 'reopened'
		&&  $this->Payload->action !== 'deleted'
		&&  $this->Payload->action !== 'pinned'
		&&  $this->Payload->action !== 'locked'
		&&  $this->Payload->action !== 'unlocked'
		&&  $this->Payload->action !== 'transferred' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		if( $this->Payload->action === 'closed' )
		{
			if( $this->Payload->issue->state_reason === 'not_planned' )
			{
				$this->Payload->action = 'closed as not planned';
			}
		}

		$Embed = [
			'title' => "Issue **#{$this->Payload->issue->number}** {$this->Payload->action}: {$this->Escape( $this->Payload->issue->title )}",
			'url' => $this->Payload->issue->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];

		if( $this->Payload->action === 'opened' )
		{
			$Embed[ 'description' ] = $this->ShortDescription( $this->Payload->issue->body );
		}

		return $Embed;
	}

	/**
	 * Formats a pull request event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#pull_request
	 */
	private function FormatPullRequestEvent( ) : array
	{
		if( $this->Payload->action === 'closed' )
		{
			if( $this->Payload->pull_request->merged === true )
			{
				$this->Payload->action = 'merged';
			}
			else
			{
				$this->Payload->action = 'closed without merging';
			}
		}
		else if( $this->Payload->action === 'ready_for_review' )
		{
			$this->Payload->action = 'readied';
		}
		else if( $this->Payload->action === 'auto_merge_enabled' )
		{
			$this->Payload->action = 'enabled auto-merge';
		}
		else if( $this->Payload->action === 'converted_to_draft' )
		{
			$this->Payload->action = 'converted to draft';
		}

		if( $this->Payload->action === 'edited'
		||  $this->Payload->action === 'synchronize'
		||  $this->Payload->action === 'labeled'
		||  $this->Payload->action === 'unlabeled'
		||  $this->Payload->action === 'assigned'
		||  $this->Payload->action === 'unassigned'
		||  $this->Payload->action === 'review_requested'
		||  $this->Payload->action === 'review_request_removed' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'opened'
		&&  $this->Payload->action !== 'reopened'
		&&  $this->Payload->action !== 'deleted'
		&&  $this->Payload->action !== 'merged'
		&&  $this->Payload->action !== 'locked'
		&&  $this->Payload->action !== 'unlocked'
		&&  $this->Payload->action !== 'readied'
		&&  $this->Payload->action !== 'enabled auto-merge'
		&&  $this->Payload->action !== 'converted to draft'
		&&  $this->Payload->action !== 'closed without merging' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Embed = [
			'title' => ( $this->Payload->pull_request->draft ? 'Draft ' : '' ) . "PR **#{$this->Payload->pull_request->number}** {$this->Payload->action}: {$this->Escape( $this->Payload->pull_request->title )}",
			'url' => $this->Payload->pull_request->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];

		if( $this->Payload->action === 'opened' )
		{
			$Embed[ 'description' ] = $this->ShortDescription( $this->Payload->pull_request->body );
		}
		else if( $this->Payload->action === 'merged' )
		{
			$Embed[ 'description' ] = "Merged from **{$this->Escape( $this->Payload->pull_request->user->login )}** to {$this->EscapeCode( $this->Payload->pull_request->base->ref )}";
		}

		return $Embed;
	}

	/**
	 * Formats a milestone event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#milestone
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

		return [
			'title' => "{$this->Payload->action} milestone **#{$this->Payload->milestone->number}**: {$this->Escape( $this->Payload->milestone->title )}",
			'description' => $this->ShortDescription( $this->Payload->milestone->description ),
			'url' => $this->Payload->milestone->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a package event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#package
	 */
	private function FormatPackageEvent( ) : array
	{
		if( $this->Payload->action !== 'published'
		&&  $this->Payload->action !== 'updated' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return [
			'title' => "{$this->Payload->action} {$this->Payload->package->package_type} package: **{$this->Escape( $this->Payload->package->name )}** {$this->Payload->package->package_version->version}",
			'description' => $this->ShortDescription( $this->Payload->package->package_version->body ),
			'url' => $this->Payload->package->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a project event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#project
	 */
	private function FormatProjectEvent( ) : array
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
			'title' => "{$this->Payload->action} project **#{$this->Payload->project->number}**: {$this->Escape( $this->Payload->project->name )}",
			'description' => $this->ShortDescription( $this->Payload->project->body ),
			'url' => $this->Payload->project->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a release event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#release
	 */
	private function FormatReleaseEvent( ) : array
	{
		if( $this->Payload->action !== 'published'
		&&  $this->Payload->action !== 'unpublished' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Name = $this->Payload->release->tag_name;

		if( !empty( $this->Payload->release->name ) && $this->Payload->release->name !== $this->Payload->release->tag_name )
		{
			$Name .= " ({$this->Payload->release->name})";
		}

		return [
			'title' => "{$this->Payload->action} a " . ( $this->Payload->release->draft ? 'draft ' : '' ) . ( $this->Payload->release->prerelease ? 'pre-' : '' ) . "release: {$this->Escape( $Name )}",
			'description' => $this->ShortDescription( $this->Payload->release->body ),
			'url' => $this->Payload->release->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a commit comment event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#commit_comment
	 */
	private function FormatCommitCommentEvent( ) : array
	{
		if( $this->Payload->action !== 'created' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return [
			'title' => "commented on commit {$this->EscapeCode( substr( $this->Payload->comment->commit_id, 0, 6 ) )}",
			'description' => $this->ShortDescription( $this->Payload->comment->body ),
			'url' => $this->Payload->comment->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a issue comment event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#issue_comment
	 */
	private function FormatIssueCommentEvent( ) : array
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action === 'created' )
		{
			return [
				'title' => "commented on issue **#{$this->Payload->issue->number}**: {$this->Escape( $this->Payload->issue->title )}",
				'description' => $this->ShortDescription( $this->Payload->comment->body ),
				'url' => $this->Payload->comment->html_url,
				'color' => $this->FormatAction(),
				'author' => $this->FormatAuthor(),
			];
		}

		if( $this->Payload->action === 'deleted' )
		{
			return [
				'title' => "deleted comment in issue **#{$this->Payload->issue->number}** from {$this->Payload->comment->user->login}",
				'url' => $this->Payload->comment->html_url,
				'color' => $this->FormatAction(),
				'author' => $this->FormatAuthor(),
			];
		}

		throw new NotImplementedException( $this->EventType, $this->Payload->action );
	}

	/**
	 * Formats a pull request review event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#pull_request_review
	 */
	private function FormatPullRequestReviewEvent( ) : array
	{
		if( $this->Payload->action !== 'submitted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		if( $this->Payload->review->state === 'commented' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->review->state );
		}

		if( $this->Payload->review->state === 'changes_requested' )
		{
			$this->Payload->review->state = 'requested changes in';
		}

		return [
			'title' => "{$this->Payload->review->state} PR **#{$this->Payload->pull_request->number}**: {$this->Escape( $this->Payload->pull_request->title )}",
			'description' => $this->ShortDescription( $this->Payload->review->body ),
			'url' => $this->Payload->review->html_url,
			'color' => $this->FormatAction( $this->Payload->review->state ),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a pull request review comment event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#pull_request_review_comment
	 */
	private function FormatPullRequestReviewCommentEvent( ) : array
	{
		if( $this->Payload->action !== 'created' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return [
			'title' => "reviewed PR **#{$this->Payload->pull_request->number}**: {$this->Escape( $this->Payload->pull_request->title )}",
			'description' => $this->ShortDescription( $this->Payload->comment->body ),
			'url' => $this->Payload->comment->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a pull request review comment event
	 * See https://docs.github.com/en/developers/webhooks-and-events/webhook-events-and-payloads#discussion
	 */
	private function FormatDiscussionEvent( ) : array
	{
		if( $this->Payload->action === 'edited'
		||  $this->Payload->action === 'labeled'
		||  $this->Payload->action === 'unlabeled'
		||  $this->Payload->action === 'answered'
		||  $this->Payload->action === 'unanswered' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action === 'category_changed' )
		{
			$this->Payload->action = 'changed category';
		}

		if( $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'deleted'
		&&  $this->Payload->action !== 'pinned'
		&&  $this->Payload->action !== 'unpinned'
		&&  $this->Payload->action !== 'locked'
		&&  $this->Payload->action !== 'unlocked'
		&&  $this->Payload->action !== 'transferred'
		&&  $this->Payload->action !== 'changed category' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		$Embed = [
			'title' => "{$this->Payload->discussion->category->emoji} Discussion **#{$this->Payload->discussion->number}** {$this->Payload->action}: {$this->Escape( $this->Payload->discussion->title )}",
			'url' => $this->Payload->discussion->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];

		if( $this->Payload->action === 'created' )
		{
			$Embed[ 'description' ] = $this->ShortDescription( $this->Payload->discussion->body );
		}

		return $Embed;
	}

	/**
	 * Formats a pull request review comment event
	 * See https://docs.github.com/en/developers/webhooks-and-events/webhook-events-and-payloads#discussion_comment
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
				'title' => "commented on discussion **#{$this->Payload->discussion->number}**: {$this->Escape( $this->Payload->discussion->title )}",
				'description' => $this->ShortDescription( $this->Payload->comment->body ),
				'url' => $this->Payload->comment->html_url,
				'color' => $this->FormatAction(),
				'author' => $this->FormatAuthor(),
			];
		}

		if( $this->Payload->action === 'deleted' )
		{
			return [
				'title' => "deleted comment in discussion **#{$this->Payload->discussion->number}** from {$this->Payload->comment->user->login}",
				'url' => $this->Payload->comment->html_url,
				'color' => $this->FormatAction(),
				'author' => $this->FormatAuthor(),
			];
		}

		throw new NotImplementedException( $this->EventType, $this->Payload->action );
	}

	/**
	 * Formats a repository vulnerability alert event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#repository_vulnerability_alert
	 */
	private function FormatRepositoryVulnerabilityAlertEvent( ) : array
	{
		if( $this->Payload->action === 'create' )
		{
			return [
				'title' => "⚠ New vulnerability for **{$this->Escape( $this->Payload->alert->affected_package_name )}**",
				'url' => $this->Payload->alert->external_reference,
				'color' => $this->FormatAction(),
				'author' => $this->FormatAuthor(),
				'fields' =>
				[
					[
						'name' => 'Affected range',
						'value' => $this->Escape( $this->Payload->alert->affected_range )
					],
					[
						'name' => 'Fixed in',
						'value' => $this->Escape( $this->Payload->alert->fixed_in )
					],
					[
						'name' => 'Identifier',
						'value' => $this->Escape( $this->Payload->alert->external_identifier )
					],
				],
			];
		}
		else if( $this->Payload->action === 'resolve' )
		{
			$this->Payload->action = 'resolved';
		}
		else if( $this->Payload->action === 'dismiss' )
		{
			$this->Payload->action = 'dismissed';
		}
		else
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return [
			'title' => "Vulnerability for **{$this->Escape( $this->Payload->alert->affected_package_name )}** {$this->Payload->action}",
			'url' => $this->Payload->alert->external_reference,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a member event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#member
	 */
	private function FormatMemberEvent( ) : array
	{
		if( $this->Payload->action !== 'added' && $this->Payload->action !== 'removed' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return [
			'title' => "{$this->Payload->action} **{$this->Escape( $this->Payload->member->login )}** as a collaborator",
			'url' => $this->Payload->repository->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a gollum event (wiki)
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#gollum
	 */
	private function FormatGollumEvent( ) : array
	{
		$Messages = [];
		
		foreach( $this->Payload->pages as $Page )
		{
			// Append compare url since github doesn't provide one
			if( $Page->action === 'edited' )
			{
				$Page->html_url .= '/_compare/' . $Page->sha;
			}
			
			$Messages[] = "[{$Page->action} {$this->Escape( $Page->title )}]({$Page->html_url})" . ( empty( $Page->summary ) ? '' : ( ': ' . $this->ShortMessage( $Page->summary ) ) );
		}
		
		return [
			'title' => "updated wiki",
			'description' => implode( "\n", $Messages ),
			'color' => $this->FormatAction( 'updated' ),
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Formats a ping event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#ping
	 */
	private function FormatPingEvent( ) : array
	{
		return [
			'title' => "Hook {$this->Payload->hook->id} worked!",
			'description' => $this->Escape( $this->Payload->zen ),
			'color' => 5025616,
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Format a public event. Without a doubt: the best GitHub event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#public
	 */
	private function FormatPublicEvent( ) : array
	{
		return [
			'title' => "{$this->Escape( $this->Payload->repository->name )} is now open source and available to everyone!",
			'url' => $this->Payload->repository->html_url,
			'color' => 5025616,
			'author' => $this->FormatAuthor(),
		];
	}

	/**
	 * Triggered when a repository is created.
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#repository
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

		$Title = "{$this->Payload->action} **{$this->Escape( $this->Payload->repository->name )}**";

		if( $this->Payload->action === 'renamed' )
		{
			$Title .= " (from *{$this->Escape( $this->Payload->changes->repository->name->from )}*)";
		}
		else if( $this->Payload->action === 'transferred' )
		{
			if( isset( $this->Payload->changes->owner->from->user ) )
			{
				$Title .= " (from *{$this->Escape( $this->Payload->changes->owner->from->user->login )}*)";
			}
			else if( isset( $this->Payload->changes->owner->from->organization ) )
			{
				$Title .= " (from *{$this->Escape( $this->Payload->changes->owner->from->organization->login )}*)";
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
