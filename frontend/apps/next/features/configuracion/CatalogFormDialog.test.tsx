import { act, fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { catalogFormSchema, locationFormSchema } from 'app/features/configuracion/schemas'
import { ApiError, ApiValidationError } from 'app/lib/api-client'
import { CatalogFormDialog, CatalogSelectField } from './CatalogFormDialog'

const save = vi.fn()
const onSaved = vi.fn()

type Saved = { id: number; name: string }

function renderBase(record: { name: string; description: string | null } | null = null) {
  render(
    <CatalogFormDialog<{ name: string; description: string | null }, Saved>
      open
      onOpenChange={vi.fn()}
      idPrefix="category"
      title={record ? 'Editar categoría' : 'Nueva categoría'}
      record={record}
      schema={catalogFormSchema}
      save={save}
      onSaved={onSaved}
    />
  )
  return screen.getByRole('dialog')
}

describe('CatalogFormDialog (FR-012, edge cases de nombre)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('envía nombre recortado y descripción vacía como null', async () => {
    save.mockResolvedValue({ data: { id: 1, name: 'EPP' } })
    const dialog = renderBase()

    await userEvent.type(within(dialog).getByLabelText('Nombre'), '  EPP  ')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(save).toHaveBeenCalledWith({ name: 'EPP', description: null })
    expect(onSaved).toHaveBeenCalledWith({ id: 1, name: 'EPP' }, true)
  })

  it('valida el nombre en el cliente sin llamar al backend', async () => {
    const dialog = renderBase()

    await userEvent.type(within(dialog).getByLabelText('Nombre'), '   ')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(await within(dialog).findByText('Ingresa el nombre.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Nombre')).toHaveAttribute('aria-invalid', 'true')
    expect(save).not.toHaveBeenCalled()
  })

  it('muestra el 422 del servidor junto al campo (duplicado, FR-006)', async () => {
    save.mockRejectedValue(new ApiValidationError('Inválido', { name: ['Ya existe una categoría con ese nombre.'] }))
    const dialog = renderBase()

    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'epp')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(await within(dialog).findByText('Ya existe una categoría con ese nombre.')).toBeInTheDocument()
    expect(onSaved).not.toHaveBeenCalled()
  })

  it('muestra otros errores del servidor como alerta del formulario', async () => {
    save.mockRejectedValue(new ApiError('No tienes permiso para esta acción.', 403))
    const dialog = renderBase()

    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'EPP')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('No tienes permiso para esta acción.')
  })

  it('al editar arranca con los datos actuales y los reenvía', async () => {
    save.mockResolvedValue({ data: { id: 3, name: 'EPP' } })
    const dialog = renderBase({ name: 'EPP', description: 'Protección personal' })

    expect(within(dialog).getByLabelText('Nombre')).toHaveValue('EPP')
    expect(within(dialog).getByLabelText(/Descripción/)).toHaveValue('Protección personal')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(save).toHaveBeenCalledWith({ name: 'EPP', description: 'Protección personal' })
    expect(onSaved).toHaveBeenCalledWith({ id: 3, name: 'EPP' }, false)
  })

  it('incluye campos extra (select) en la validación y el envío', async () => {
    save.mockResolvedValue({ data: { id: 9, name: 'Máquina 1' } })
    render(
      <CatalogFormDialog
        open
        onOpenChange={vi.fn()}
        idPrefix="location"
        title="Nueva ubicación"
        record={null}
        schema={locationFormSchema}
        readExtraValues={(formData) => ({ location_type_id: Number(formData.get('location_type_id')) || undefined })}
        extraFields={(errors) => (
          <CatalogSelectField
            id="location-type"
            name="location_type_id"
            label="Tipo de ubicación"
            placeholder="Selecciona un tipo"
            items={[{ value: '2', label: 'Vehículo' }]}
            defaultValue={null}
            error={errors.location_type_id}
          />
        )}
        save={save}
        onSaved={onSaved}
      />
    )
    const dialog = screen.getByRole('dialog')

    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'Máquina 1')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))
    expect(await within(dialog).findByText('Selecciona un tipo de ubicación.')).toBeInTheDocument()
    expect(save).not.toHaveBeenCalled()

    fireEvent.click(within(dialog).getByRole('combobox', { name: 'Tipo de ubicación' }))
    const option = await screen.findByRole('option', { name: 'Vehículo' })
    await act(async () => {
      fireEvent.pointerDown(option)
      fireEvent.click(option)
    })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(save).toHaveBeenCalledWith({ name: 'Máquina 1', description: null, location_type_id: 2 })
  })

  it('muestra un aviso en lugar del formulario cuando está bloqueado', async () => {
    const onOpenChange = vi.fn()
    render(
      <CatalogFormDialog
        open
        onOpenChange={onOpenChange}
        idPrefix="location"
        title="Nueva ubicación"
        record={null}
        schema={locationFormSchema}
        blocked="Primero crea un tipo de ubicación activo."
        save={save}
        onSaved={onSaved}
      />
    )
    const dialog = screen.getByRole('dialog', { name: 'Nueva ubicación' })

    expect(within(dialog).getByText('Primero crea un tipo de ubicación activo.')).toBeInTheDocument()
    expect(within(dialog).queryByLabelText('Nombre')).not.toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Entendido' }))
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })
})
