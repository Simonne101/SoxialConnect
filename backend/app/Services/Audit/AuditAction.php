<?php

namespace App\Services\Audit;

/** Actions tracées dans audit_logs (docs/database.md §3.8, docs/security.md §13). */
enum AuditAction: string
{
    case Login = 'LOGIN';
    case LoginFailed = 'LOGIN_FAILED';
    case Logout = 'LOGOUT';
    case Registered = 'REGISTERED';
    case EmailVerified = 'EMAIL_VERIFIED';
    case PasswordChanged = 'PASSWORD_CHANGED';
    case PasswordReset = 'PASSWORD_RESET';
    case ProfileUpdated = 'PROFILE_UPDATED';
    case TwoFactorEnabled = '2FA_ENABLED';
    case TwoFactorDisabled = '2FA_DISABLED';
    case SessionRevoked = 'SESSION_REVOKED';
    case OauthConnected = 'OAUTH_CONNECTED';
    case OauthRevoked = 'OAUTH_REVOKED';
    case OauthReconnected = 'OAUTH_RECONNECTED';
    case TokenRefreshed = 'TOKEN_REFRESHED';
    case TokenRefreshFailed = 'TOKEN_REFRESH_FAILED';
    case PostCreated = 'POST_CREATED';
    case PostUpdated = 'POST_UPDATED';
    case PostDeleted = 'POST_DELETED';
    case PostScheduled = 'POST_SCHEDULED';
    case PostPublished = 'POST_PUBLISHED';
    case PostCancelled = 'POST_CANCELLED';
    case MediaUploaded = 'MEDIA_UPLOADED';
    case MediaDeleted = 'MEDIA_DELETED';
    case MediaRejected = 'MEDIA_REJECTED';
    case WebhookReceived = 'WEBHOOK_RECEIVED';
    case WebhookRejected = 'WEBHOOK_REJECTED';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
}
