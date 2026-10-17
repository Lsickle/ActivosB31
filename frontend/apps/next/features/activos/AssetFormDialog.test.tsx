import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiValidationError } from 'app/lib/api-client'
import { AssetFormDialog } from './AssetFormDialog'
import { asset, chooseOption, options } from './test-fixtures'

const create = vi.fn()
const update = vi.fn()
const uploadPhoto = vi.fn()
const deletePhoto = vi.fn()
const toastError = vi.fn()

vi.mock('app/features/activos/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/activos/api')>()),
  assetsApi: {
    create: (...args: unknown[]) => create(...args),
    update: (...args: unknown[]) => update(...args),
    uploadPhoto: (...args: unknown[]) => uploadPhoto(...args),
    deletePhoto: (...args: unknown[]) => deletePhoto(...args),
  },
}))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: (...args: unknown[]) => toastError(...args) } }))

const onSaved = vi.fn()

function renderForm(record = null as ReturnType<typeof asset> | null) {
  return render(
    <AssetFormDialog open onOpenChange={vi.fn()} asset={record} options={options} optionsFailed={false} onSaved={onSaved} />
  )
}

function photoFile(name = 'casco.jpg', type = 'image/jpeg', size = 1024) {
  const file = new File(['x'], name, { type })
  Object.defineProperty(file, 'size', { value: size })
  return file
}

describe('AssetFormDialog (005 US1, FR-001, FR-002, RN-008)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:preview'), revokeObjectURL: vi.fn() }))
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('solo ofrece tipos serializados activos (RN-007, US1-3)', async () => {
    renderForm()
    const dialog = screen.getByRole('dialog', { name: 'Nuevo activo' })

    fireEvent.click(within(dialog).getByRole('combobox', { name: 'Tipo de activo' }))
    expect(await screen.findByRole('option', { name: 'Cilindro SCBA (EPP)' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Radio portátil (Comunicaciones)' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /Guantes de rescate/ })).not.toBeInTheDocument()
  })

  it('valida en el cliente: tipo y placa obligatorios, valor negativo', async () => {
    renderForm()
    const dialog = screen.getByRole('dialog', { name: 'Nuevo activo' })

    await userEvent.type(within(dialog).getByLabelText(/Valor de adquisición/), '-5')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar activo' }))

    expect(await within(dialog).findByText('Selecciona el tipo de activo.')).toBeInTheDocument()
    expect(within(dialog).getByText('Ingresa la placa de inventario.')).toBeInTheDocument()
    expect(within(dialog).getByText('El valor no puede ser negativo.')).toBeInTheDocument()
    expect(create).not.toHaveBeenCalled()
  })

  it('registra con foto (vista previa) y la placa en mayúsculas (US1-1, D-006)', async () => {
    const created = asset()
    create.mockResolvedValue({ data: created })
    renderForm()
    const dialog = screen.getByRole('dialog', { name: 'Nuevo activo' })

    await chooseOption(within(dialog).getByRole('combobox', { name: 'Tipo de activo' }), 'Cilindro SCBA (EPP)')
    await userEvent.type(within(dialog).getByLabelText(/Placa/), ' b31-0001 ')
    await userEvent.type(within(dialog).getByLabelText(/Marca/), 'MSA')
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Ubicación' }), 'Bodega principal')

    const photo = photoFile()
    await userEvent.upload(within(dialog).getByLabelText(/^Foto/), photo)
    expect(within(dialog).getByRole('img', { name: 'Vista previa de la foto' })).toHaveAttribute('src', 'blob:preview')

    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar activo' }))

    await waitFor(() => expect(create).toHaveBeenCalled())
    const [values, sentPhoto] = create.mock.calls[0]!
    expect(values).toMatchObject({ asset_type_id: 3, plate: 'B31-0001', brand: 'MSA', location_id: 4, serial: null })
    expect(sentPhoto).toBe(photo)
    await waitFor(() => expect(onSaved).toHaveBeenCalledWith(created, true))
  })

  it('rechaza fotos de otro formato o de más de 4 MB sin enviarlas', async () => {
    renderForm()
    const dialog = screen.getByRole('dialog', { name: 'Nuevo activo' })
    const input = within(dialog).getByLabelText(/^Foto/)
    expect(within(dialog).getByText('JPEG, PNG o WebP, máximo 4 MB.')).toBeInTheDocument()

    await userEvent.upload(input, photoFile('foto.gif', 'image/gif'), { applyAccept: false })
    expect(await within(dialog).findByText('La foto debe ser JPEG, PNG o WebP.')).toBeInTheDocument()

    await userEvent.upload(input, photoFile('grande.jpg', 'image/jpeg', 4 * 1024 * 1024 + 1))
    expect(await within(dialog).findByText('La foto no puede superar 4 MB.')).toBeInTheDocument()
    expect(within(dialog).queryByRole('img', { name: 'Vista previa de la foto' })).not.toBeInTheDocument()
  })

  it('muestra junto al campo la placa duplicada del servidor (US1-2)', async () => {
    create.mockRejectedValue(new ApiValidationError('Inválido', { plate: ['La placa ya está registrada.'] }))
    renderForm()
    const dialog = screen.getByRole('dialog', { name: 'Nuevo activo' })

    await chooseOption(within(dialog).getByRole('combobox', { name: 'Tipo de activo' }), 'Cilindro SCBA (EPP)')
    await userEvent.type(within(dialog).getByLabelText(/Placa/), 'b31-0001')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar activo' }))

    expect(await within(dialog).findByText('La placa ya está registrada.')).toBeInTheDocument()
    expect(onSaved).not.toHaveBeenCalled()
  })

  it('al editar no muestra ubicación (tiene su acción), conserva el tipo no disponible y reemplaza la foto', async () => {
    const record = asset({
      photo_url: 'https://cdn.example/foto.jpg',
      asset_type: { id: 99, name: 'Casco antiguo', tracking_mode: 'serialized', category: { id: 1, name: 'EPP' } },
    })
    update.mockResolvedValue({ data: record })
    uploadPhoto.mockResolvedValue({ data: record })
    renderForm(record)
    const dialog = screen.getByRole('dialog', { name: 'Editar activo B31-0001' })

    expect(within(dialog).queryByRole('combobox', { name: 'Ubicación' })).not.toBeInTheDocument()
    expect(within(dialog).getByRole('combobox', { name: 'Tipo de activo' })).toHaveTextContent('Casco antiguo (no disponible)')
    expect(within(dialog).getByRole('img', { name: 'Foto actual' })).toHaveAttribute('src', 'https://cdn.example/foto.jpg')
    expect(within(dialog).getByLabelText(/Placa/)).toHaveValue('B31-0001')

    const photo = photoFile('nueva.png', 'image/png')
    await userEvent.upload(within(dialog).getByLabelText(/^Foto/), photo)
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(update).toHaveBeenCalled())
    const [id, values] = update.mock.calls[0]!
    expect(id).toBe(7)
    expect(values).not.toHaveProperty('location_id')
    expect(values).toMatchObject({ asset_type_id: 99, plate: 'B31-0001' })
    await waitFor(() => expect(uploadPhoto).toHaveBeenCalledWith(7, photo))
    expect(deletePhoto).not.toHaveBeenCalled()
    await waitFor(() => expect(onSaved).toHaveBeenCalledWith(record, false))
  })

  it('al editar puede quitar la foto actual', async () => {
    const record = asset({ photo_url: 'https://cdn.example/foto.jpg' })
    update.mockResolvedValue({ data: record })
    deletePhoto.mockResolvedValue(null)
    renderForm(record)
    const dialog = screen.getByRole('dialog', { name: 'Editar activo B31-0001' })

    await userEvent.click(within(dialog).getByRole('button', { name: 'Quitar foto' }))
    expect(within(dialog).queryByRole('img', { name: 'Foto actual' })).not.toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(deletePhoto).toHaveBeenCalledWith(7))
    expect(uploadPhoto).not.toHaveBeenCalled()
  })

  it('si los datos se guardan pero la foto falla, avisa y no pierde la edición', async () => {
    const record = asset()
    update.mockResolvedValue({ data: record })
    uploadPhoto.mockRejectedValue(new Error('Archivo dañado'))
    renderForm(record)
    const dialog = screen.getByRole('dialog', { name: 'Editar activo B31-0001' })

    await userEvent.upload(within(dialog).getByLabelText(/^Foto/), photoFile())
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(toastError).toHaveBeenCalledWith('Los datos se guardaron, pero no fue posible actualizar la foto: Archivo dañado'))
    expect(onSaved).toHaveBeenCalledWith(record, false)
  })
})
