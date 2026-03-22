import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { ArrowLeft, Plus, Trash2, GripVertical, Save } from 'lucide-react';

interface Props {
  triggerOptions: { value: string; label: string }[];
  variables: Record<string, string>;
}

interface Step {
  id: number;
  message: string;
  delay_minutes: number;
  delay_type: 'minutes' | 'hours' | 'days';
}

export default function CreateSequence({ triggerOptions, variables }: Props) {
  const [steps, setSteps] = useState<Step[]>([
    { id: 1, message: '', delay_minutes: 0, delay_type: 'minutes' },
  ]);

  const { data, setData, post, processing, errors } = useForm({
    name: '',
    description: '',
    trigger_event: '',
    steps: steps,
  });

  const addStep = () => {
    const newStep: Step = {
      id: Date.now(),
      message: '',
      delay_minutes: 30,
      delay_type: 'minutes',
    };
    const newSteps = [...steps, newStep];
    setSteps(newSteps);
    setData('steps', newSteps);
  };

  const removeStep = (id: number) => {
    if (steps.length <= 1) return;
    const newSteps = steps.filter((s) => s.id !== id);
    setSteps(newSteps);
    setData('steps', newSteps);
  };

  const updateStep = (id: number, field: keyof Step, value: any) => {
    const newSteps = steps.map((s) => (s.id === id ? { ...s, [field]: value } : s));
    setSteps(newSteps);
    setData('steps', newSteps);
  };

  const insertVariable = (stepId: number, variable: string) => {
    const step = steps.find((s) => s.id === stepId);
    if (step) {
      updateStep(stepId, 'message', step.message + variable);
    }
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/sms/sequences');
  };

  return (
    <AppLayout>
      <Head title="Create SMS Sequence" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center gap-4">
          <Link href="/sms/sequences">
            <Button variant="ghost" size="icon">
              <ArrowLeft className="h-5 w-5" />
            </Button>
          </Link>
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Create Sequence</h1>
            <p className="text-muted-foreground">
              Set up automated follow-up messages
            </p>
          </div>
        </div>

        <form onSubmit={handleSubmit}>
          <div className="grid gap-6 lg:grid-cols-3">
            {/* Main Content */}
            <div className="lg:col-span-2 space-y-6">
              {/* Sequence Details */}
              <Card>
                <CardHeader>
                  <CardTitle>Sequence Details</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="name">Sequence Name</Label>
                    <Input
                      id="name"
                      placeholder="e.g., Delivery Follow-up"
                      value={data.name}
                      onChange={(e) => setData('name', e.target.value)}
                    />
                    {errors.name && (
                      <p className="text-sm text-destructive">{errors.name}</p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="description">Description (Optional)</Label>
                    <Textarea
                      id="description"
                      placeholder="Describe what this sequence does..."
                      value={data.description}
                      onChange={(e) => setData('description', e.target.value)}
                      rows={2}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label>Trigger Event</Label>
                    <Select
                      value={data.trigger_event}
                      onValueChange={(value) => setData('trigger_event', value)}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select when to trigger this sequence" />
                      </SelectTrigger>
                      <SelectContent>
                        {triggerOptions.map((option) => (
                          <SelectItem key={option.value} value={option.value}>
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {errors.trigger_event && (
                      <p className="text-sm text-destructive">{errors.trigger_event}</p>
                    )}
                  </div>
                </CardContent>
              </Card>

              {/* Steps */}
              <Card>
                <CardHeader>
                  <CardTitle>Sequence Steps</CardTitle>
                  <CardDescription>
                    Add messages that will be sent in order. Each step can have a delay.
                  </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                  {steps.map((step, index) => (
                    <div
                      key={step.id}
                      className="border rounded-lg p-4 space-y-4"
                    >
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                          <GripVertical className="h-4 w-4 text-muted-foreground cursor-grab" />
                          <span className="font-medium">Step {index + 1}</span>
                        </div>
                        {steps.length > 1 && (
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => removeStep(step.id)}
                          >
                            <Trash2 className="h-4 w-4 text-destructive" />
                          </Button>
                        )}
                      </div>

                      {index > 0 && (
                        <div className="flex items-center gap-2">
                          <Label className="whitespace-nowrap">Wait</Label>
                          <Input
                            type="number"
                            min="0"
                            className="w-20"
                            value={step.delay_minutes}
                            onChange={(e) =>
                              updateStep(step.id, 'delay_minutes', parseInt(e.target.value) || 0)
                            }
                          />
                          <Select
                            value={step.delay_type}
                            onValueChange={(value: 'minutes' | 'hours' | 'days') =>
                              updateStep(step.id, 'delay_type', value)
                            }
                          >
                            <SelectTrigger className="w-28">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="minutes">Minutes</SelectItem>
                              <SelectItem value="hours">Hours</SelectItem>
                              <SelectItem value="days">Days</SelectItem>
                            </SelectContent>
                          </Select>
                          <span className="text-muted-foreground">after previous step</span>
                        </div>
                      )}

                      <div className="space-y-2">
                        <div className="flex items-center justify-between">
                          <Label>Message</Label>
                          <div className="flex gap-1">
                            {Object.keys(variables).slice(0, 3).map((variable) => (
                              <Button
                                key={variable}
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-6 text-xs"
                                onClick={() => insertVariable(step.id, variable)}
                              >
                                {variable}
                              </Button>
                            ))}
                          </div>
                        </div>
                        <Textarea
                          placeholder="Hi {name}, your order {waybill} is now {status}..."
                          value={step.message}
                          onChange={(e) => updateStep(step.id, 'message', e.target.value)}
                          rows={3}
                          className="font-mono"
                        />
                        <div className="text-xs text-muted-foreground text-right">
                          {step.message.length} characters
                        </div>
                      </div>
                    </div>
                  ))}

                  <Button type="button" variant="outline" onClick={addStep} className="w-full">
                    <Plus className="mr-2 h-4 w-4" />
                    Add Step
                  </Button>
                </CardContent>
              </Card>
            </div>

            {/* Sidebar */}
            <div className="space-y-6">
              {/* Variables */}
              <Card>
                <CardHeader>
                  <CardTitle>Available Variables</CardTitle>
                  <CardDescription>
                    Click to insert into the active step
                  </CardDescription>
                </CardHeader>
                <CardContent>
                  <div className="space-y-2">
                    {Object.entries(variables).map(([variable, description]) => (
                      <div
                        key={variable}
                        className="flex justify-between items-center text-sm"
                      >
                        <code className="bg-muted px-2 py-0.5 rounded">{variable}</code>
                        <span className="text-muted-foreground text-xs">{description}</span>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              {/* Preview */}
              <Card>
                <CardHeader>
                  <CardTitle>Sequence Preview</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="space-y-3">
                    {steps.map((step, index) => (
                      <div key={step.id} className="relative pl-4 border-l-2 border-muted">
                        <div className="absolute -left-[5px] top-0 h-2 w-2 rounded-full bg-primary" />
                        <div className="text-xs text-muted-foreground mb-1">
                          {index === 0 ? 'Immediately' : `${step.delay_minutes} ${step.delay_type} later`}
                        </div>
                        <div className="text-sm bg-muted p-2 rounded truncate">
                          {step.message || 'Empty message...'}
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              {/* Actions */}
              <div className="flex flex-col gap-2">
                <Button type="submit" disabled={processing} className="w-full">
                  <Save className="mr-2 h-4 w-4" />
                  Create Sequence
                </Button>
                <Link href="/sms/sequences" className="w-full">
                  <Button type="button" variant="outline" className="w-full">
                    Cancel
                  </Button>
                </Link>
              </div>
            </div>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
