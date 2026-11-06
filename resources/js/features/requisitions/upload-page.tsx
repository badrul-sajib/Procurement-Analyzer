import { CheckCircle2, CircleAlert, FileText, Trash2, Upload } from 'lucide-react'
import { useRef, useState } from 'react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { DatePicker } from '@/components/date-range-filter'
import { PageHeader } from '@/components/page-header'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Spinner } from '@/components/ui/spinner'
import { useLookups } from '@/features/catalog/api'
import { useUploadRequisitions, type UploadResult } from '@/features/requisitions/api'
import { ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/forms'
import { useSettings } from '@/lib/settings'
import { cn } from '@/lib/utils'

const ACCEPT = ['.pdf', '.xlsx', '.xls', '.txt']
const MAX_FILES = 20

export function UploadPage() {
  const settings = useSettings()
  const departments = useLookups('departments', { is_active: 1 })
  const upload = useUploadRequisitions()
  const input = useRef<HTMLInputElement>(null)
  const [files, setFiles] = useState<File[]>([])
  const [dragging, setDragging] = useState(false)
  const [departmentId, setDepartmentId] = useState<number | null>(null)
  const [date, setDate] = useState<string | null>(null)
  const [result, setResult] = useState<UploadResult | null>(null)
  const maxBytes = (settings.data?.max_upload_mb ?? 10) * 1024 * 1024

  // Quick client-side checks for feedback only; the server re-validates type, content and size.
  const problem = (f: File) =>
    !ACCEPT.some((ext) => f.name.toLowerCase().endsWith(ext)) ? 'Unsupported type' : f.size > maxBytes ? `Larger than ${settings.data?.max_upload_mb} MB` : null

  const add = (list: FileList | null) => {
    if (!list) return
    setResult(null)
    setFiles((prev) => [...prev, ...Array.from(list).filter((f) => !prev.some((p) => p.name === f.name && p.size === f.size))].slice(0, MAX_FILES))
  }

  const submit = async () => {
    try {
      const res = await upload.mutateAsync({ files, department_id: departmentId, requisition_date: date })
      setResult(res)
      setFiles([])
      if (res.created.length) toast.success(`${res.created.length} requisition(s) queued for processing`)
    } catch (e) {
      toast.error(e instanceof ApiError && Object.keys(e.errors).length ? Object.values(e.errors)[0][0] : errorMessage(e))
    }
  }

  const invalid = files.some((f) => problem(f))
  return (
    <>
      <PageHeader title="Upload requisitions" description="PDF, Excel or text files. Each file becomes its own requisition and is processed in the background." />
      <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <Card>
          <CardContent className="space-y-4">
            <button
              type="button"
              onClick={() => input.current?.click()}
              onDragOver={(e) => { e.preventDefault(); setDragging(true) }}
              onDragLeave={() => setDragging(false)}
              onDrop={(e) => { e.preventDefault(); setDragging(false); add(e.dataTransfer.files) }}
              className={cn('flex w-full flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-10 text-center transition-colors',
                dragging ? 'border-primary bg-primary/5' : 'border-muted-foreground/25 hover:bg-muted/50')}
            >
              <Upload className="size-8 text-muted-foreground" />
              <span className="font-medium">Drop files here or click to browse</span>
              <span className="text-sm text-muted-foreground">
                {ACCEPT.join(', ')} · up to {settings.data?.max_upload_mb ?? 10} MB each · {MAX_FILES} files at a time
              </span>
            </button>
            <input ref={input} type="file" multiple accept={ACCEPT.join(',')} className="hidden" onChange={(e) => { add(e.target.files); e.target.value = '' }} />

            {files.length > 0 && (
              <ul className="divide-y rounded-lg border">
                {files.map((f, i) => (
                  <li key={`${f.name}-${i}`} className="flex items-center gap-3 px-3 py-2 text-sm">
                    <FileText className="size-4 shrink-0 text-muted-foreground" />
                    <span className="min-w-0 flex-1 truncate">{f.name}</span>
                    <span className="text-muted-foreground">{(f.size / 1024).toFixed(0)} KB</span>
                    {problem(f) && <span className="text-destructive">{problem(f)}</span>}
                    <Button variant="ghost" size="icon" className="size-7" aria-label={`Remove ${f.name}`} onClick={() => setFiles(files.filter((_, j) => j !== i))}><Trash2 /></Button>
                  </li>
                ))}
              </ul>
            )}
            <div className="flex justify-end">
              <Button onClick={submit} disabled={!files.length || invalid || upload.isPending}>
                {upload.isPending && <Spinner />} Upload {files.length > 0 && `${files.length} file${files.length > 1 ? 's' : ''}`}
              </Button>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Defaults</CardTitle>
            <CardDescription>Optional. Used when the document doesn't state them.</CardDescription>
          </CardHeader>
          <CardContent>
            <FieldGroup>
              <Field>
                <FieldLabel>Department</FieldLabel>
                <Select value={departmentId ? String(departmentId) : 'none'} onValueChange={(v) => setDepartmentId(v === 'none' ? null : Number(v))}>
                  <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">From document</SelectItem>
                    {departments.data?.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
                  </SelectContent>
                </Select>
              </Field>
              <Field>
                <FieldLabel>Requisition date</FieldLabel>
                <DatePicker value={date} onChange={setDate} placeholder="From document" />
              </Field>
            </FieldGroup>
          </CardContent>
        </Card>
      </div>

      {result && (
        <div className="space-y-3">
          {result.created.length > 0 && (
            <Alert>
              <CheckCircle2 />
              <AlertTitle>Uploaded</AlertTitle>
              <AlertDescription>
                <ul className="mt-1 space-y-1">
                  {result.created.map((r) => (
                    <li key={r.id}><Link className="font-medium underline-offset-4 hover:underline" to={`/requisitions/${r.id}`}>{r.reference_no}</Link> — {r.title}</li>
                  ))}
                </ul>
              </AlertDescription>
            </Alert>
          )}
          {result.duplicates.length > 0 && (
            <Alert variant="destructive">
              <CircleAlert />
              <AlertTitle>Already uploaded — skipped</AlertTitle>
              <AlertDescription>
                <ul className="mt-1 space-y-1">
                  {result.duplicates.map((d) => (
                    <li key={d.file}>
                      {d.file}
                      {d.requisition && <> is <Link className="font-medium underline" to={`/requisitions/${d.requisition.id}`}>{d.requisition.reference_no}</Link></>}
                    </li>
                  ))}
                </ul>
              </AlertDescription>
            </Alert>
          )}
        </div>
      )}
    </>
  )
}
