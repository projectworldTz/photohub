<?php

namespace App\Enums;

enum GalleryStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case Published = 'published';
    case AwaitingSelection = 'awaiting_selection';
    case SelectionCompleted = 'selection_completed';
    case Editing = 'editing';
    case Final = 'final';
    case Delivered = 'delivered';
    case Archived = 'archived';
}
