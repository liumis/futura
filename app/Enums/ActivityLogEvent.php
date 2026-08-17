<?php

namespace App\Enums;

enum ActivityLogEvent: string
{
    case AuthLogin = 'auth.login';
    case AuthLoginFailed = 'auth.login_failed';

    case OrderCreated = 'order.created';
    case OrderUpdated = 'order.updated';
    case OrderStatusChanged = 'order.status_changed';
    case OrderShippingCostChanged = 'order.shipping_cost_changed';
    case OrderTrackingChanged = 'order.tracking_changed';
    case OrderLineItemsSynced = 'order.line_items_synced';
    case OrderDeleted = 'order.deleted';

    case CargoCreated = 'cargo.created';
    case CargoUpdated = 'cargo.updated';
    case CargoDeleted = 'cargo.deleted';
    case CargoLineItemsSynced = 'cargo.line_items_synced';

    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductDeleted = 'product.deleted';
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserDeleted = 'user.deleted';

    case CollectionCreated = 'collection.created';
    case CollectionUpdated = 'collection.updated';
    case CollectionDeleted = 'collection.deleted';

    case CustomerLevelCreated = 'customer_level.created';
    case CustomerLevelUpdated = 'customer_level.updated';
    case CustomerLevelDeleted = 'customer_level.deleted';

    case CustomerLevelPriceCreated = 'customer_level_price.created';
    case CustomerLevelPriceUpdated = 'customer_level_price.updated';
    case CustomerLevelPriceDeleted = 'customer_level_price.deleted';

    case InvoiceCreated = 'invoice.created';
    case InvoiceUpdated = 'invoice.updated';
    case InvoiceDeleted = 'invoice.deleted';

    case TodoCreated = 'todo.created';
    case TodoUpdated = 'todo.updated';
    case TodoDeleted = 'todo.deleted';
    case TodoCommentCreated = 'todo_comment.created';
    case TodoCommentUpdated = 'todo_comment.updated';
    case TodoCommentDeleted = 'todo_comment.deleted';
    case TodoCalendarSchedulingUpdated = 'todo.calendar_scheduling_updated';
    case TodoCalendarEventDeletedExternally = 'todo.calendar_event_deleted_externally';

    case ShippingSettingsUpdated = 'shipping_settings.updated';

    case ReportGenerated = 'report.generated';
    case ReportDownloaded = 'report.downloaded';
}
