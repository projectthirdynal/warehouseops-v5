import { useState } from 'react';
import {
  MapPin,
  Package,
  Clock,
  User,
  MessageSquare,
  ChevronDown,
  ChevronUp,
  AlertCircle,
} from 'lucide-react';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CallButton } from '@/components/leads/CallButton';
import { OutcomeModal } from '@/components/leads/OutcomeModal';
import { formatCurrency, formatDateTime, formatRelativeTime } from '@/lib/utils';
import type { AgentLead, PoolStatus } from '@/types/lead-pool';

interface LeadCardProps {
  lead: AgentLead;
  onUpdate?: () => void;
}

const poolStatusConfig: Record<PoolStatus, { label: string; color: string }> = {
  AVAILABLE: { label: 'Available', color: 'bg-green-100 text-green-800' },
  ASSIGNED: { label: 'Assigned', color: 'bg-blue-100 text-blue-800' },
  COOLDOWN: { label: 'Cooldown', color: 'bg-yellow-100 text-yellow-800' },
  EXHAUSTED: { label: 'Exhausted', color: 'bg-red-100 text-red-800' },
};

export function LeadCard({ lead, onUpdate }: LeadCardProps) {
  const [isExpanded, setIsExpanded] = useState(false);
  const [showOutcomeModal, setShowOutcomeModal] = useState(false);

  const statusCfg = poolStatusConfig[lead.pool_status];
  const hasCustomerHistory = lead.customer && lead.customer.total_orders > 0;

  return (
    <>
      <Card className="overflow-hidden">
        <CardHeader className="pb-2">
          <div className="flex items-start justify-between">
            <div className="space-y-1">
              <div className="flex items-center gap-2">
                <h3 className="font-semibold text-lg">{lead.name}</h3>
                <Badge className={statusCfg.color}>{statusCfg.label}</Badge>
              </div>
              <div className="flex items-center gap-4 text-sm text-muted-foreground">
                <span className="flex items-center gap-1">
                  <MapPin className="h-3 w-3" />
                  {[lead.barangay, lead.city, lead.state].filter(Boolean).join(', ') || 'No address'}
                </span>
                {lead.product_name && (
                  <span className="flex items-center gap-1">
                    <Package className="h-3 w-3" />
                    {lead.product_name}
                    {lead.product_brand && ` (${lead.product_brand})`}
                  </span>
                )}
              </div>
            </div>
            <div className="text-right">
              {lead.amount && (
                <div className="text-lg font-bold text-green-600">
                  {formatCurrency(lead.amount)}
                </div>
              )}
              <div className="text-sm text-muted-foreground">
                Cycle {lead.total_cycles} | {lead.call_attempts} calls
              </div>
            </div>
          </div>
        </CardHeader>

        <CardContent className="space-y-4">
          {/* Customer History Alert */}
          {hasCustomerHistory && (
            <div className="flex items-center gap-2 p-2 bg-blue-50 rounded-lg text-sm">
              <User className="h-4 w-4 text-blue-600" />
              <span>
                Returning customer: {lead.customer!.successful_orders}/{lead.customer!.total_orders} orders
                ({lead.customer!.success_rate}% success)
              </span>
            </div>
          )}

          {/* Last Called Info */}
          {lead.last_called_at && (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Clock className="h-4 w-4" />
              Last called: {formatRelativeTime(lead.last_called_at)}
            </div>
          )}

          {/* Action Buttons */}
          <div className="flex items-center gap-2">
            <CallButton
              leadId={lead.id}
              onCallInitiated={() => onUpdate?.()}
            />
            <Button
              variant="outline"
              onClick={() => setShowOutcomeModal(true)}
            >
              <MessageSquare className="mr-2 h-4 w-4" />
              Record Outcome
            </Button>
            <Button
              variant="ghost"
              size="icon"
              onClick={() => setIsExpanded(!isExpanded)}
            >
              {isExpanded ? (
                <ChevronUp className="h-4 w-4" />
              ) : (
                <ChevronDown className="h-4 w-4" />
              )}
            </Button>
          </div>

          {/* Expanded Details */}
          {isExpanded && (
            <div className="pt-4 border-t space-y-4">
              {/* Lead Details */}
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <span className="text-muted-foreground">Status:</span>
                  <span className="ml-2 font-medium">{lead.status}</span>
                </div>
                <div>
                  <span className="text-muted-foreground">Sales Status:</span>
                  <span className="ml-2 font-medium">{lead.sales_status}</span>
                </div>
                <div>
                  <span className="text-muted-foreground">Assigned:</span>
                  <span className="ml-2">
                    {lead.assigned_at ? formatDateTime(lead.assigned_at) : 'N/A'}
                  </span>
                </div>
                <div>
                  <span className="text-muted-foreground">Created:</span>
                  <span className="ml-2">{formatDateTime(lead.created_at)}</span>
                </div>
              </div>

              {/* Cycle History */}
              {lead.cycles && lead.cycles.length > 0 && (
                <div className="space-y-2">
                  <h4 className="font-medium text-sm">Call History</h4>
                  <div className="space-y-2">
                    {lead.cycles.slice(0, 5).map((cycle) => (
                      <div
                        key={cycle.id}
                        className="flex items-center justify-between p-2 bg-muted/50 rounded text-sm"
                      >
                        <div className="flex items-center gap-2">
                          <Badge variant="outline">Cycle {cycle.cycle_number}</Badge>
                          <span>{cycle.call_count} calls</span>
                          {cycle.outcome && (
                            <Badge variant="secondary">{cycle.outcome}</Badge>
                          )}
                        </div>
                        <div className="text-muted-foreground">
                          {cycle.last_call_at
                            ? formatRelativeTime(cycle.last_call_at)
                            : 'No calls yet'}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Callback Alert */}
              {lead.cycles?.some((c) => c.callback_at && c.status === 'ACTIVE') && (
                <div className="flex items-center gap-2 p-2 bg-yellow-50 rounded-lg text-sm">
                  <AlertCircle className="h-4 w-4 text-yellow-600" />
                  <span>
                    Callback scheduled:{' '}
                    {formatDateTime(
                      lead.cycles.find((c) => c.callback_at && c.status === 'ACTIVE')!.callback_at!
                    )}
                  </span>
                </div>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      <OutcomeModal
        leadId={lead.id}
        isOpen={showOutcomeModal}
        onClose={() => setShowOutcomeModal(false)}
        onSuccess={() => onUpdate?.()}
      />
    </>
  );
}
