<?php

declare(strict_types=1);

// Status updates are broadcast on public channels named after the
// notification or batch UUID (`notifications.{id}`, `batches.{id}`). The API
// itself has no authentication, so the unguessable UUID acts as the
// capability, the same as for GET /notifications/{id}. With authenticated
// API consumers these would become private channels authorised here.
