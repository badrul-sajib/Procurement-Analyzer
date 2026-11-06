import { AlertTriangle, ArrowLeft, CheckCheck, Download, FileWarning, Loader2, RotateCcw, Save, ShieldCheck, Undo2, XCircle } from 'lucide-react'
import { useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { PageHeader } from '@/components/page-header'
import { StatusBadge } from '@/components/status-badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  EDITABLE, IN_PROGRESS, useRequisition, useRequisitionAction, useSaveRequisition, type Requisition, type RequisitionLine,
} from '@/features/requisitions/api'
import { RequisitionReviewForm, type ReviewFormHandle } from '@/features/requisitions/requisition-review-form'
import { ApiError, downloadUrl } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { errorMessage } from '@/lib/forms'
import { formatDate, formatDateTime, formatInt, formatQty, titleCase } from '@/lib/format'

export function NewRequisitionPage() {
  const navigate = useNavigate()
  const form = useRef<ReviewFormHandle>(null)
  const save = useSaveRequisition()
  const submit = async () => {
    const payload = await form.current?.payload()
    if (!payload) return
    try {
      const r = await save.mutateAsync(payload)
      toast.success(`${r.reference_no} created as draft`)
      navigate(`/requisitions/${r.id}`)
    } catch (e) {
      form.current?.applyErrors(e)
    }
  }
  return (
    <>
      <BackLink />
      <PageHeader title="New requisition" description="Enter a requisition received on paper, by phone or email."
        actions={<Button onClick={submit} disabled={save.isPending}>{save.isPending ? <Spinner /> : <Save />} Save draft</Button>} />
      <RequisitionReviewForm ref={form} />
    </>
  )
}

export function RequisitionPage() {
  const id = Number(useParams().id)
  const { data: requisition, isLoading } = useRequisition(id)
  if (isLoading || !requisition) return <Skeleton className="h-96 w-full" />
  return <RequisitionView requisition={requisition} />
}

function BackLink() {
  return (
    <Button variant="ghost" size="sm" className="-ml-2 w-fit" asChild>
      <Link to="/requisitions"><ArrowLeft /> Requisitions</Link>
    </Button>
  )
}

function RequisitionView({ requisition: r }: { requisition: Requisition }) {
  const { can } = useAuth()
  const form = useRef<ReviewFormHandle>(null)
  const save = useSaveRequisition(r.id)
  const actions = useRequisitionAction(r.id)
  const editable = EDITABLE.includes(r.status) && can('requisitions.update')
  const lastLog = r.processing_logs?.[0]

  const saveForm = async (quiet = false): Promise<boolean> => {
    const payload = await form.current?.payload()
    if (!payload) return false
    try {
      await save.mutateAsync(payload)
      if (!quiet) toast.success('Changes saved')
      return true
    } catch (e) {
      form.current?.applyErrors(e)
      return false
    }
  }

  const verify = async () => {
    if (editable && form.current?.isDirty() && !(await saveForm(true))) return
    try {
      await actions.verify.mutateAsync(undefined)
      toast.success('Requisition verified')
    } catch (e) {
      form.current?.applyErrors(e)
      if (e instanceof ApiError && e.code === 'verification_failed') toast.error(`${Object.keys(e.errors).length} problem(s) to fix before verifying`)
    }
  }

  const run = (mutation: { mutateAsync: (b?: unknown) => Promise<unknown> }, message: string, body?: unknown) =>
    mutation.mutateAsync(body).then(() => toast.success(message)).catch((e) => toast.error(errorMessage(e)))

  return (
    <>
      <BackLink />
      <PageHeader
        title={r.title}
        description={`${r.reference_no}${r.external_reference ? ` · Document ref ${r.external_reference}` : ''}`}
        actions={<>
          <StatusBadge status={r.status} />
          {r.file && (
            <Button variant="outline" asChild>
              <a href={downloadUrl(`/requisitions/${r.id}/document`)}><Download /> Original</a>
            </Button>
          )}
          {editable && r.file && (
            <ConfirmDialog trigger={<Button variant="outline"><RotateCcw /> Reprocess</Button>} title="Reprocess document?"
              description="Extraction runs again on the original file and replaces the current lines, including any corrections you made."
              confirmLabel="Reprocess" onConfirm={() => run(actions.process, 'Reprocessing started')} />
          )}
          {editable && <Button variant="outline" onClick={() => saveForm()} disabled={save.isPending}>{save.isPending ? <Spinner /> : <Save />} Save</Button>}
          {EDITABLE.includes(r.status) && can('requisitions.verify') && (
            <Button onClick={verify} disabled={actions.verify.isPending || save.isPending}>{actions.verify.isPending ? <Spinner /> : <ShieldCheck />} Verify</Button>
          )}
          {r.status === 'verified' && can('requisitions.verify') && (
            <ConfirmDialog trigger={<Button variant="outline"><Undo2 /> Reopen</Button>} title="Reopen for correction?"
              description="The requisition leaves the analyzer until it is verified again." confirmLabel="Reopen"
              onConfirm={() => run(actions.reopen, 'Reopened for correction')} />
          )}
          {r.status === 'verified' && can('requisitions.approve') && <ApproveDialog requisition={r} />}
          {r.status !== 'cancelled' && can('requisitions.cancel') && (
            <ConfirmDialog trigger={<Button variant="ghost" className="text-destructive"><XCircle /> Cancel</Button>} title="Cancel requisition?"
              description="Cancelled requisitions are kept for audit but never count in totals. Requisitions with active purchase orders can't be cancelled."
              destructive requireReason confirmLabel="Cancel requisition"
              onConfirm={(reason) => run(actions.cancel, 'Requisition cancelled', { reason })} />
          )}
        </>}
      />

      {IN_PROGRESS.includes(r.status) && (
        <Alert><Loader2 className="animate-spin" /><AlertTitle>Processing document…</AlertTitle>
          <AlertDescription>Extraction is running in the background. This page updates automatically.</AlertDescription></Alert>
      )}
      {r.status === 'failed' && (
        <Alert variant="destructive"><FileWarning /><AlertTitle>Extraction failed</AlertTitle>
          <AlertDescription>{lastLog?.message ?? 'The document could not be read.'} You can add the lines manually below, then verify.</AlertDescription></Alert>
      )}
      {r.status === 'cancelled' && (
        <Alert><XCircle /><AlertTitle>Cancelled {r.cancelled_at && `on ${formatDateTime(r.cancelled_at)}`} by {r.cancelled_by?.name}</AlertTitle>
          <AlertDescription>{r.cancellation_reason}</AlertDescription></Alert>
      )}
      {!!r.possible_duplicates?.length && (
        <Alert><AlertTriangle /><AlertTitle>Possible duplicate</AlertTitle>
          <AlertDescription>
            Other requisitions carry the same document reference:{' '}
            {r.possible_duplicates.map((d, i) => <span key={d.id}>{i > 0 && ', '}<Link className="underline" to={`/requisitions/${d.id}`}>{d.reference_no}</Link> ({d.status})</span>)}
          </AlertDescription></Alert>
      )}

      <Summary requisition={r} />

      {EDITABLE.includes(r.status) ? (
        editable ? <RequisitionReviewForm ref={form} requisition={r} /> : <LinesTable requisition={r} />
      ) : (
        !IN_PROGRESS.includes(r.status) && <LinesTable requisition={r} />
      )}

      {!!r.processing_logs?.length && <ProcessingLogCard requisition={r} />}
    </>
  )
}

function Summary({ requisition: r }: { requisition: Requisition }) {
  const items: [string, string][] = [
    ['Requisition date', formatDate(r.requisition_date)],
    ['Department', r.department?.name ?? '—'],
    ['Lines', formatInt(r.line_count)],
    ['Distinct items', formatInt(r.item_count)],
    ['Requested qty (base units)', r.total_requested_quantity ? formatQty(r.total_requested_quantity) : 'After verification'],
    ['Uploaded', `${formatDateTime(r.uploaded_at)} · ${r.created_by?.name ?? ''}`],
  ]
  if (r.verified_at) items.push(['Verified', `${formatDateTime(r.verified_at)} · ${r.verified_by?.name ?? ''}`])
  if (r.approved_at) items.push(['Approved', `${formatDateTime(r.approved_at)} · ${r.approved_by?.name ?? ''}`])
  return (
    <Card>
      <CardContent>
        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
          {items.map(([k, v]) => (
            <div key={k}><dt className="text-muted-foreground">{k}</dt><dd className="font-medium">{v}</dd></div>
          ))}
        </dl>
      </CardContent>
    </Card>
  )
}

function LinesTable({ requisition: r }: { requisition: Requisition }) {
  const approved = r.status === 'approved'
  const unit = (l: RequisitionLine) => l.item?.default_unit?.name ?? ''
  return (
    <Card>
      <CardHeader><CardTitle>Lines</CardTitle></CardHeader>
      <CardContent className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-8">#</TableHead>
              <TableHead>As written</TableHead>
              <TableHead>Catalog item</TableHead>
              <TableHead className="text-right">Requested</TableHead>
              <TableHead className="text-right">In default unit</TableHead>
              <TableHead className="text-right">Approved</TableHead>
              {approved && <>
                <TableHead className="text-right">On order</TableHead>
                <TableHead className="text-right">Purchased</TableHead>
                <TableHead className="text-right">Pending</TableHead>
                <TableHead className="text-right">Delivered</TableHead>
              </>}
              <TableHead>Destination</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {r.lines?.map((l) => (
              <TableRow key={l.id}>
                <TableCell className="text-muted-foreground">{l.line_no}</TableCell>
                <TableCell>{l.original_name}{l.notes && <div className="text-xs text-muted-foreground">{l.notes}</div>}</TableCell>
                <TableCell>{l.item ? <Link className="hover:underline" to={`/items/${l.item.id}`}>{l.item.name}</Link> : '—'}
                  {l.match_type && <div className="text-xs text-muted-foreground">{titleCase(l.match_type)} match</div>}</TableCell>
                <TableCell className="text-right tabular-nums">{formatQty(l.quantity)} {l.unit?.name}</TableCell>
                <TableCell className="text-right tabular-nums">{l.base_quantity ? `${formatQty(l.base_quantity)} ${unit(l)}` : '—'}</TableCell>
                <TableCell className="text-right tabular-nums">{formatQty(l.approved_quantity)}</TableCell>
                {approved && <>
                  <TableCell className="text-right tabular-nums">{formatQty(l.fulfilment?.on_order)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatQty(l.fulfilment?.purchased)}</TableCell>
                  <TableCell className="text-right font-medium tabular-nums">{formatQty(l.fulfilment?.pending)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatQty(l.fulfilment?.delivered)}</TableCell>
                </>}
                <TableCell>{l.department?.name ?? '—'}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}

function ApproveDialog({ requisition: r }: { requisition: Requisition }) {
  const [open, setOpen] = useState(false)
  const [values, setValues] = useState<Record<number, string>>({})
  const { approve } = useRequisitionAction(r.id)
  const lines = r.lines ?? []

  const invalid = lines.some((l) => {
    const v = values[l.id]
    return v !== undefined && (!/^\d+(\.\d{1,3})?$/.test(v) || Number(v) > Number(l.base_quantity))
  })

  const submit = async () => {
    try {
      await approve.mutateAsync({ lines: Object.entries(values).map(([id, q]) => ({ id: Number(id), approved_quantity: q })) })
      toast.success('Requisition approved')
      setOpen(false)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  return (
    <Dialog open={open} onOpenChange={(o) => { setOpen(o); if (o) setValues({}) }}>
      <DialogTrigger asChild><Button><CheckCheck /> Approve</Button></DialogTrigger>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Approve {r.reference_no}</DialogTitle>
          <DialogDescription>Only approved quantities can be purchased. Lower a line to approve less; 0 rejects it.</DialogDescription>
        </DialogHeader>
        <div className="max-h-[50vh] overflow-y-auto">
          <Table>
            <TableHeader><TableRow><TableHead>Item</TableHead><TableHead className="text-right">Requested</TableHead><TableHead className="w-36 text-right">Approve</TableHead></TableRow></TableHeader>
            <TableBody>
              {lines.map((l) => (
                <TableRow key={l.id}>
                  <TableCell>{l.item?.name}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatQty(l.base_quantity)} {l.item?.default_unit?.name}</TableCell>
                  <TableCell>
                    <Input inputMode="decimal" className="text-right tabular-nums" aria-label={`Approved quantity for ${l.item?.name}`}
                      value={values[l.id] ?? String(Number(l.base_quantity))}
                      aria-invalid={values[l.id] !== undefined && Number(values[l.id]) > Number(l.base_quantity)}
                      onChange={(e) => setValues({ ...values, [l.id]: e.target.value })} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <DialogFooter>
          <Button onClick={submit} disabled={invalid || approve.isPending}>{approve.isPending && <Spinner />} Approve</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function ProcessingLogCard({ requisition: r }: { requisition: Requisition }) {
  return (
    <Collapsible>
      <Card>
        <CardHeader className="flex-row items-center justify-between">
          <CardTitle>Processing log</CardTitle>
          <CollapsibleTrigger asChild><Button variant="ghost" size="sm">Show {r.processing_logs?.length} attempt(s)</Button></CollapsibleTrigger>
        </CardHeader>
        <CollapsibleContent>
          <CardContent className="space-y-3">
            {r.processing_logs?.map((log) => (
              <div key={log.id} className="rounded-lg border p-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">Attempt {log.attempt}</span>
                  <StatusBadge status={log.status === 'succeeded' ? 'completed' : log.status === 'failed' ? 'failed' : 'processing'} label={titleCase(log.status)} />
                  {log.extractor && <span className="text-muted-foreground">via {log.extractor.replace('_', ' ')}</span>}
                  <span className="ml-auto text-muted-foreground">{formatDateTime(log.started_at)}</span>
                </div>
                {log.message && <p className="mt-1">{log.message}</p>}
                {!!log.context?.skipped_lines?.length && (
                  <div className="mt-2">
                    <p className="text-muted-foreground">Lines that could not be read (check them against the document):</p>
                    <ul className="mt-1 list-disc pl-5 text-muted-foreground">{log.context.skipped_lines.map((s, i) => <li key={i}>{s}</li>)}</ul>
                  </div>
                )}
              </div>
            ))}
          </CardContent>
        </CollapsibleContent>
      </Card>
    </Collapsible>
  )
}
