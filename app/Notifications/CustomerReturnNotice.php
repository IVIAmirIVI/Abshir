<?php

namespace App\Notifications;

/** Informational database-only notice; never requests approval. */
class CustomerReturnNotice extends InternalStaffNotice {}
