import assert from 'node:assert/strict'
import { test } from 'node:test'
import { formatoDuracion, formatoPrecio, textoMaximoRestante } from './formato.js'

test('los precios chicos muestran decimales suficientes', () => {
  assert.equal(formatoPrecio('0.05969'), '$0.05969')
  assert.equal(formatoPrecio(0.00012346), '$0.0001235')
  assert.equal(formatoPrecio('0.000000123456'), '$0.0000001235')
  assert.notEqual(formatoPrecio('0.05969'), '$0.06')
  assert.notEqual(formatoPrecio('0.00012345'), '$0.00')
})

test('los precios medianos y grandes mantienen una presentación razonable', () => {
  assert.equal(formatoPrecio('0.5'), '$0.50')
  assert.equal(formatoPrecio('1.23456'), '$1.2346')
  assert.equal(formatoPrecio('612.5'), '$612.5000')
  assert.equal(formatoPrecio('67000.126'), '$67000.13')
})

test('valores no numéricos o cero', () => {
  assert.equal(formatoPrecio(null), '—')
  assert.equal(formatoPrecio(undefined), '—')
  assert.equal(formatoPrecio('abc'), '—')
  assert.equal(formatoPrecio('0'), '$0.00')
})

test('formatoDuracion', () => {
  assert.equal(formatoDuracion(5 * 3600 + 32 * 60), '5h 32m')
  assert.equal(formatoDuracion(3 * 3600), '3h')
  assert.equal(formatoDuracion(50 * 60), '50m')
  assert.equal(formatoDuracion(-10), '0m')
})

test('el countdown de posición abierta parte del límite de 8h del backend', () => {
  const abierta = Date.parse('2026-10-02T10:00:00Z')
  const venceEn = new Date(abierta + 8 * 3600 * 1000).toISOString()

  assert.equal(textoMaximoRestante(venceEn, abierta), 'Máximo restante: 8h')
  assert.equal(textoMaximoRestante(venceEn, abierta + (2 * 3600 + 28 * 60) * 1000), 'Máximo restante: 5h 32m')
  assert.equal(textoMaximoRestante(venceEn, abierta + 8 * 3600 * 1000), 'Máximo alcanzado: cierre en la próxima revisión')
  assert.equal(textoMaximoRestante(venceEn, abierta + 9 * 3600 * 1000), 'Máximo alcanzado: cierre en la próxima revisión')
  assert.equal(textoMaximoRestante(null, abierta), '')
})
