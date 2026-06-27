<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Repository;

use FormattingFramework;
use InterfaceDB;

final class PhotoAuditRepository
{
    public function fetchRecentPhotoAudit(int $limit = 100): array
    {
        if (!InterfaceDB::tableExists('photo_audit')) {
            return [];
        }

        $hasPhotos = InterfaceDB::tableExists('photos');
        $hasEvents = InterfaceDB::tableExists('events');
        $hasUsers = InterfaceDB::tableExists('users');
        $hasUploadTokens = InterfaceDB::tableExists('api_upload_tokens');

        $photoSelect = $hasPhotos ? 'COALESCE(photo.original_filename, \'\')' : '\'\'';
        $eventSelect = $hasEvents ? 'COALESCE(event.event_name, \'\')' : '\'\'';
        $actorSelect = $hasUsers ? 'COALESCE(actor.display_name, \'\')' : '\'\'';
        $tokenSelect = $hasUploadTokens ? 'COALESCE(token.token_label, \'\')' : '\'\'';
        $joins = '';

        if ($hasPhotos) {
            $joins .= ' LEFT JOIN photos photo
                ON photo.id = audit.photo_id';
        }
        if ($hasEvents) {
            $joins .= ' LEFT JOIN events event
                ON event.id = audit.event_id';
        }
        if ($hasUsers) {
            $joins .= ' LEFT JOIN users actor
                ON actor.id = audit.actor_user_id';
        }
        if ($hasUploadTokens) {
            $joins .= ' LEFT JOIN api_upload_tokens token
                ON token.id = audit.upload_token_id';
        }

        return InterfaceDB::fetchAll(
            'SELECT
                audit.id,
                audit.photo_id,
                audit.event_id,
                audit.actor_user_id,
                audit.upload_token_id,
                audit.action_type,
                audit.details_json,
                audit.device_id,
                audit.ip_address,
                audit.user_agent,
                audit.occurred_at,
                ' . $photoSelect . ' AS original_filename,
                ' . $eventSelect . ' AS event_name,
                ' . $actorSelect . ' AS actor_user_display_name,
                ' . $tokenSelect . ' AS upload_token_label
             FROM photo_audit audit
             ' . $joins . '
             ORDER BY audit.occurred_at DESC, audit.id DESC
             LIMIT ' . FormattingFramework::normaliseLimit($limit)
        );
    }
}
