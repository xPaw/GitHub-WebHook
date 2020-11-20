<?php
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
		||  $this->EventType === 'status' )
		{
			throw new IgnoredEventException( $this->EventType );
		}

		$Embed = null;

		switch( $this->EventType )
		{
			case 'ping'          : $Embed = $this->FormatPingEvent( ); break;
			//case 'push'          : $Embed = $this->FormatPushEvent( ); break;
			//case 'delete'        : $Embed = $this->FormatDeleteEvent( ); break;
			//case 'public'        : $Embed = $this->FormatPublicEvent( ); break;
			case 'issues'        : $Embed = $this->FormatIssuesEvent( ); break;
			//case 'member'        : $Embed = $this->FormatMemberEvent( ); break;
			//case 'gollum'        : $Embed = $this->FormatGollumEvent( ); break;
			//case 'package'       : $Embed = $this->FormatPackageEvent( ); break;
			//case 'project'       : $Embed = $this->FormatProjectEvent( ); break;
			//case 'release'       : $Embed = $this->FormatReleaseEvent( ); break;
			//case 'milestone'     : $Embed = $this->FormatMilestoneEvent( ); break;
			case 'repository'    : $Embed = $this->FormatRepositoryEvent( ); break;
			case 'pull_request'  : $Embed = $this->FormatPullRequestEvent( ); break;
			//case 'issue_comment' : $Embed = $this->FormatIssueCommentEvent( ); break;
			//case 'commit_comment': $Embed = $this->FormatCommitCommentEvent( ); break;
			//case 'pull_request_review': $Embed = $this->FormatPullRequestReviewEvent( ); break;
			//case 'pull_request_review_comment': $Embed = $this->FormatPullRequestReviewCommentEvent( ); break;
			//case 'repository_vulnerability_alert': $Embed = $this->FormatRepositoryVulnerabilityAlertEvent( ); break;
		}

		if( empty( $Embed ) )
		{
			throw new NotImplementedException( $this->EventType );
		}

		return [
			'embeds' => [ $Embed ],
		];
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

	private function FormatFooter() : array
	{
		return [
			'text' => $this->Payload->repository->full_name,
			'icon_url' => 'https://docs.github.com/assets/images/site/favicon.svg',
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
			case 'created'    :
			case 'resolved'   :
			case 'reopened'   : return 16750592;
			case 'locked'     :
			case 'deleted'    :
			case 'dismissed'  :
			case 'force-pushed':
			case 'requested changes':
			case 'closed without merging':
			case 'closed'     : return 16007990;
			case 'merged'     : return 7291585;
			default           : return 5025616;
		}
	}

	/**
	 * Formats a push event
	 * See https://developer.github.com/v3/activity/events/types/#pushevent
	 */
	private function FormatPushEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
	}

	/**
	 * Formats a deletion event
	 * See https://developer.github.com/v3/activity/events/types/#deleteevent
	 */
	private function FormatDeleteEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
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

		$Embed = [
			'title' => "Issue **#{$this->Payload->issue->number}** {$this->Payload->action}: {$this->Escape( $this->Payload->issue->title )}",
			'url' => $this->Payload->issue->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
			'footer' => $this->FormatFooter(),
		];

		if( $this->Payload->action === 'opened' )
		{
			$Embed[ 'description' ] = $this->Escape( $this->Payload->issue->body );
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
		&&  $this->Payload->action !== 'closed without merging' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Embed = [
			'title' => ( $this->Payload->pull_request->draft ? 'Draft ' : '' ) . "PR **#{$this->Payload->pull_request->number}** {$this->Payload->action}: {$this->Escape( $this->Payload->pull_request->title )}",
			'url' => $this->Payload->pull_request->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
			'footer' => $this->FormatFooter(),
		];

		if( $this->Payload->action === 'opened' )
		{
			$Embed[ 'description' ] = $this->Escape( $this->Payload->pull_request->body );
		}
		else if( $this->Payload->action === 'merged' )
		{
			$Embed[ 'description' ] = "Merged from **{$this->Payload->pull_request->user->login}** to `{$this->Escape( $this->Payload->pull_request->base->ref )}`";
		}

		return $Embed;
	}

	/**
	 * Formats a milestone event
	 * See https://developer.github.com/v3/activity/events/types/#milestoneevent
	 */
	private function FormatMilestoneEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
	}

	/**
	 * Formats a package event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#package
	 */
	private function FormatPackageEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
	}

	/**
	 * Formats a project event
	 * See https://developer.github.com/v3/activity/events/types/#projectevent
	 */
	private function FormatProjectEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
	}

	/**
	 * Formats a release event
	 * See https://developer.github.com/v3/activity/events/types/#releaseevent
	 */
	private function FormatReleaseEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
	}

	/**
	 * Formats a commit comment event
	 * See https://developer.github.com/v3/activity/events/types/#commitcommentevent
	 */
	private function FormatCommitCommentEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
	}

	/**
	 * Formats a issue comment event
	 * See https://developer.github.com/v3/activity/events/types/#issuecommentevent
	 */
	private function FormatIssueCommentEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
	}

	/**
	 * Formats a pull request review event
	 * See https://developer.github.com/v3/activity/events/types/#pullrequestreviewevent
	 */
	private function FormatPullRequestReviewEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
	}

	/**
	 * Formats a pull request review comment event
	 * See https://developer.github.com/v3/activity/events/types/#pullrequestreviewcommentevent
	 */
	private function FormatPullRequestReviewCommentEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
	}

	/**
	 * Formats a repository vulnerability alert event
	 * See https://developer.github.com/v3/activity/events/types/#repositoryvulnerabilityalertevent
	 */
	private function FormatRepositoryVulnerabilityAlertEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
	}

	/**
	 * Formats a member event
	 * See https://developer.github.com/v3/activity/events/types/#memberevent
	 */
	private function FormatMemberEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
	}

	/**
	 * Formats a gollum event (wiki)
	 * See https://developer.github.com/v3/activity/events/types/#gollumevent
	 */
	private function FormatGollumEvent( ) : string
	{
		throw new NotImplementedException( $this->EventType );
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
			'url' => $this->Payload->repository->html_url,
			'color' => 5025616,
			'author' => $this->FormatAuthor(),
			'footer' => $this->FormatFooter(),
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
			'footer' => $this->FormatFooter(),
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

		return [
			'title' => "{$this->Payload->action} **{$this->Escape( $this->Payload->repository->name )}**",
			'url' => $this->Payload->repository->html_url,
			'color' => $this->FormatAction(),
			'author' => $this->FormatAuthor(),
			'footer' => $this->FormatFooter(),
		];
	}
}
